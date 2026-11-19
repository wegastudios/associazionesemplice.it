@extends('errors.layout')
@section('title', 'Qualcosa è andato storto')
@section('heading', 'Qualcosa è andato storto')
@section('message', request()->is('area', 'area/*') ? 'Non è colpa tua. Riprova tra poco; se succede ancora, scrivi alla segreteria.' : 'Non è colpa tua. Riprova tra poco; se succede ancora, premi «Chiedi aiuto».')
