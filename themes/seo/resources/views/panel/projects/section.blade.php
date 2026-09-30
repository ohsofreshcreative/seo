@extends('panel.layouts.app', ['active' => $section])

@section('title', $title . ' · ' . $project->name)

@section('content')
  <x-panel.page-header :title="$title" :description="$project->name" />
  <x-panel.empty-state title="Moduł w przygotowaniu" :description="$description" />
@endsection
