@extends('smartcars3phpvms7api::layouts.admin')

@section('title', 'SmartCARS3phpVMS7Api')
@section('actions')
    <li>
        <a href="{{ url('/smartcars3phpvms7api/admin/create') }}">
            <i class="ti-plus"></i>
            Add New</a>
    </li>
@endsection
@section('content')
    <div class="card border-blue-bottom">
        <div class="header"><h4 class="title">Admin Scaffold!</h4></div>
        <div class="content">
            <p>This view is loaded from module: {{ config('smartcars3phpvms7api.name') }}</p>
        </div>
    </div>
@endsection
