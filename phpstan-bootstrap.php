<?php

/*
 * Larastan checks `view('teams::…')` against the view finder of the
 * application it boots, and that application does not load this package's
 * provider. The namespace is added here so the check sees the addon's own
 * views instead of reporting them as unknown.
 */

use Illuminate\Contracts\View\Factory as ViewFactory;
use Larastan\Larastan\ApplicationResolver;

$app = ApplicationResolver::resolve();

$app->make(ViewFactory::class)->getFinder()->addNamespace('teams', __DIR__.'/resources/views');
