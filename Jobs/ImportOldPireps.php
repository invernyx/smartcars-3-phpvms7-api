<?php

namespace Modules\SmartCARS3phpVMS7Api\Jobs;

use App\Models\Enums\AcarsType;
use App\Models\Enums\PirepStatus;
use App\Models\Pirep;
use App\Models\Acars;
use App\Models\PirepComment;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class ImportOldPireps
 * @package Modules\SmartCARS3phpVMS7Api\Jobs
 */
class ImportOldPireps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TABLE = 'smartCARS3_FlightData';

    private const SIM_TIME_LENGTH = 10;

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        set_time_limit(0);

        $last = null;
        while (($data = $this->nextRow($last)) !== null) {
            $last = $data;
            $pirep = Pirep::find($data->pirepID);
            if ($pirep === null) {
                continue;
            }
            try {
                $isImported = DB::transaction(function () use ($pirep, $data) {
                    return $this->importRow($pirep, $data);
                });
            } catch (\Throwable $e) {
                if (!Schema::hasTable(self::TABLE)) {
                    return;
                }
                Log::warning('smartCARS: legacy flight data for PIREP '.$data->pirepID.' could not be imported: '.$e->getMessage());
                continue;
            }
            if (!$isImported) {
                continue;
            }
            // Deleted after the commit: on a MyISAM table the delete is not rolled back, and the dedupe makes a rerun harmless
            try {
                $this->legacyRow($data)->delete();
            } catch (\Throwable $e) {
                if (!Schema::hasTable(self::TABLE)) {
                    return;
                }
                Log::warning('smartCARS: legacy flight data for PIREP '.$data->pirepID.' was imported but its row could not be deleted: '.$e->getMessage());
            }
        }

        if (!Schema::hasTable(self::TABLE)) {
            return;
        }
        $remaining = DB::table(self::TABLE)->count();
        if ($remaining === 0) {
            Schema::dropIfExists(self::TABLE);
            return;
        }
        Log::warning('smartCARS: '.$remaining.' legacy flight data rows could not be imported or have no matching PIREP and stay in '.self::TABLE);
    }

    private function nextRow(?object $last): ?object
    {
        if (!Schema::hasTable(self::TABLE)) {
            return null;
        }
        $query = DB::table(self::TABLE)->orderBy('pilotID')->orderBy('pirepID');
        if ($last !== null) {
            $query->where(function ($query) use ($last) {
                $query->where('pilotID', '>', $last->pilotID)
                    ->orWhere(function ($query) use ($last) {
                        $query->where('pilotID', $last->pilotID)->where('pirepID', '>', $last->pirepID);
                    });
            });
        }
        return $query->first();
    }

    private function importRow(Pirep $pirep, object $data): bool
    {
        // Serializes overlapping runs on the same PIREP; the legacy table may be MyISAM, so its own row lock cannot be relied on.
        if (Pirep::whereKey($pirep->id)->lockForUpdate()->first() === null) {
            return false;
        }
        if (!$this->legacyRow($data)->exists()) {
            return false;
        }
        $this->importComments($pirep, $data);
        $this->importLocations($pirep, $data);
        return true;
    }

    private function legacyRow(object $data)
    {
        return DB::table(self::TABLE)
            ->where('pilotID', $data->pilotID)
            ->where('pirepID', $data->pirepID);
    }

    private function importComments(Pirep $pirep, object $data): void
    {
        $existing = [];
        foreach (PirepComment::where('pirep_id', $pirep->id)->get(['comment', 'created_at']) as $comment) {
            $existing[$this->commentKey($comment)] = true;
        }

        $log = json_decode(gzdecode($data->log));
        foreach ($log as $event) {
            $pirepLog = new PirepComment();
            $pirepLog->pirep_id = $pirep->id;
            $pirepLog->user_id = $pirep->user_id;
            $pirepLog->comment = $event->message;
            $pirepLog->created_at = $event->eventTimestamp;
            $key = $this->commentKey($pirepLog);
            if (isset($existing[$key])) {
                continue;
            }
            $pirepLog->save();
            $existing[$key] = true;
        }
    }

    private function importLocations(Pirep $pirep, object $data): void
    {
        $existing = [];
        $points = Acars::where('pirep_id', $pirep->id)
            ->where('type', AcarsType::FLIGHT_PATH)
            ->toBase()
            ->get(['order', 'sim_time']);
        foreach ($points as $point) {
            $existing[$this->locationKey((int) $point->order, $point->sim_time)] = true;
        }

        $nextOrder = 0;
        $locationData = json_decode(gzdecode($data->locations));
        foreach ($locationData as $location) {
            $order = $nextOrder++;
            $simTime = str_pad($order, self::SIM_TIME_LENGTH, '0', STR_PAD_LEFT);
            $key = $this->locationKey($order, $simTime);
            if (isset($existing[$key])) {
                continue;
            }
            $acars = new Acars();
            $acars->pirep_id = $pirep->id;
            $acars->type = AcarsType::FLIGHT_PATH;
            $acars->status = PirepStatus::ENROUTE;
            $acars->lat= $location->latitude;
            $acars->lon = $location->longitude;
            $acars->heading = round($location->heading);
            $acars->order = $order;
            $acars->sim_time = $simTime;
            $acars->created_at = $pirep->created_at;
            $acars->save();
            $existing[$key] = true;
        }
    }

    private function commentKey(PirepComment $comment): string
    {
        return $comment->comment.'|'.$comment->created_at?->format(self::DATE_FORMAT);
    }

    private function locationKey(?int $order, ?string $simTime): string
    {
        return $order.'|'.$simTime;
    }
}
