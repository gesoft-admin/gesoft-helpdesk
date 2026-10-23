@extends('layouts.app')

@section('title', __('Connected applications'))

@section('content')
<div class="container">
    <div class="flexy-container">
        <div class="flexy-item">
            <span class="heading">{{ __('Connected applications') }}@if (count($connected)) <small>({{ count($connected) }})</small>@endif</span>
        </div>
    </div>

    <p class="text-help margin-top">{{ __('An application is connected from its own settings page, by an administrator of this helpdesk. Revoking one stops its chat at once; connecting it again keeps its people\'s conversations.') }}</p>

    @if (!count($connected) && !count($configured))
        <div class="margin-top gesoft-apps-empty">{{ __('No applications are connected.') }}</div>
    @else
        <div class="table-responsive margin-top">
            <table class="table table-striped gesoft-apps">
                <thead>
                    <tr>
                        <th>{{ __('Application') }}</th>
                        <th>{{ __('Address') }}</th>
                        <th>{{ __('Key') }}</th>
                        <th>{{ __('Connected by') }}</th>
                        <th>{{ __('Last used') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($connected as $app)
                        <tr class="@if ($app->revoked_at) text-muted @endif" data-provider="{{ $app->provider }}">
                            <td>{{ $app->name }}<br><small><code>{{ $app->provider }}</code></small></td>
                            <td><code>{{ $app->origin }}</code></td>
                            <td><small><code>{{ $app->fingerprint() }}</code></small></td>
                            <td>{{ $app->connector ? $app->connector->getFullName() : '' }}<br><small>{{ App\User::dateFormat($app->updated_at) }}</small></td>
                            <td>{{ $app->last_used_at ? App\User::dateFormat($app->last_used_at) : __('Never') }}</td>
                            <td class="text-right">
                                @if ($app->revoked_at)
                                    <span class="label label-default">{{ __('Revoked') }}</span>
                                @else
                                    <form method="POST" action="{{ route('gesoftlivechat.apps.revoke', ['id' => $app->id]) }}"
                                          class="gesoft-app-revoke-form" data-confirm="{{ __('Revoke this application? Its chat stops working at once.') }}">
                                        {{ csrf_field() }}
                                        <button type="submit" class="btn btn-default btn-xs gesoft-app-revoke">{{ __('Revoke') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($configured as $provider => $app)
                        @if (!$connected->where('provider', $provider)->whereStrict('revoked_at', null)->count())
                        <tr data-provider="{{ $provider }}">
                            <td>{{ $app['name'] }}<br><small><code>{{ $provider }}</code></small></td>
                            <td><code>{{ $app['origin'] }}</code></td>
                            <td><small>{{ __('Shared secret') }}</small></td>
                            <td colspan="2"><small>{{ __('Configured on the server (.env)') }}</small></td>
                            <td></td>
                        </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@section('javascript')
    @parent
    $('.gesoft-app-revoke-form').on('submit', function () {
        return window.confirm(this.getAttribute('data-confirm'));
    });
@endsection
