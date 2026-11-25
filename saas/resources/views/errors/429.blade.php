@extends('errors.layout')
@section('title', 'Troppi tentativi')
@section('heading', 'Troppi tentativi')
@section('message', AppModulesCoreHttpTooManyAttempts::page($exception))
