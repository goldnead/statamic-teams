{{--
    The invitation page. Publish with `php artisan vendor:publish --tag=teams-views`
    and replace it with the site's own layout.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $team?->name ?? __('teams::messages.nav') }}</title>
    <style>
        body { margin: 0; padding: 48px 16px; background: #f5f5f4; color: #1c1917; font: 16px/1.5 -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; }
        main { max-width: 480px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        p { margin: 0 0 16px; }
        button { font: inherit; padding: 10px 18px; border: 0; border-radius: 6px; background: #1c1917; color: #fff; cursor: pointer; }
        .error { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    @if ($error)
        <h1>{{ __('teams::invitation.unavailable') }}</h1>
        <p class="error">{{ $error }}</p>
    @else
        <h1>{{ __('teams::invitation.heading', ['team' => $team->name]) }}</h1>
        <p>{{ __('teams::invitation.role', ['role' => $role]) }}</p>
        @if ($errors->teams->any())
            <p class="error">{{ $errors->teams->first() }}</p>
        @endif
        <form method="POST" action="{{ $acceptUrl }}">
            @csrf
            <input type="hidden" name="_redirect" value="{{ config('teams.invitations.after_accept', '/') }}">
            <button type="submit">{{ __('teams::invitation.accept') }}</button>
        </form>
    @endif
</main>
</body>
</html>
