@extends('layouts.app')

@section('title', __('Blocked chat visitors'))

@section('content')
<div class="container">
    <div class="flexy-container">
        <div class="flexy-item">
            <span class="heading">{{ __('Blocked chat visitors') }}@if (count($blocks)) <small>({{ count($blocks) }})</small>@endif</span>
        </div>
    </div>

    <p class="text-help margin-top">{{ __('Blocks end on their own when they expire; unblocking ends one now.') }}</p>

    @if (!count($blocks))
        <div class="margin-top gesoft-blocks-empty">{{ __('No blocked visitors.') }}</div>
    @else
        <div class="table-responsive margin-top">
            <table class="table table-striped gesoft-blocks">
                <thead>
                    <tr>
                        <th>{{ __('IP address') }} / {{ __('Email') }}</th>
                        <th>{{ __('Expires') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th>{{ __('Blocked by') }}</th>
                        <th>{{ __('Blocked on') }}</th>
                        <th>{{ __('Conversation') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($blocks as $block)
                        <tr>
                            <td>
                                <span class="label label-default">{{ $block->kind === 'ip' ? __('IP address') : __('Email') }}</span>
                                <code>{{ $block->value }}</code>
                            </td>
                            <td>{{ $block->expires_at ? App\User::dateFormat($block->expires_at) : __('Never') }}</td>
                            <td>{{ $block->reason }}</td>
                            <td>{{ $block->creator ? $block->creator->getFullName() : '' }}</td>
                            <td>{{ App\User::dateFormat($block->created_at) }}</td>
                            <td>
                                @if ($block->conversation)
                                    <a href="{{ $block->conversation->url() }}">#{{ $block->conversation->number }}</a>
                                @endif
                            </td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('gesoftlivechat.agent.unblock', ['id' => $block->id]) }}">
                                    {{ csrf_field() }}
                                    <button type="submit" class="btn btn-default btn-xs">{{ __('Unblock') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
