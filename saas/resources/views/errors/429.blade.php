@extends('errors.layout')
@section('title', 'Troppi tentativi')
@section('heading', 'Troppi tentativi')
@section('message', \App\Modules\Core\Http\TooManyAttempts::page($exception))
