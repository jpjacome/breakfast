<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

/*
|--------------------------------------------------------------------------
| No test may reach the internet
|--------------------------------------------------------------------------
| An HTTP call that no test faked throws instead of going out. Without this, a
| request the fake did not match simply left the machine: on 2026-08-13,
| switching AI_PROVIDER on a laptop made the client tests call the real
| OpenRouter API — with the real key, spending real credits and sending the
| test prompts to a provider. The only symptom was a handful of assertion
| failures that read like ordinary breakage.
|
| In beforeEach rather than at the top of this file: Pest.php is evaluated
| before the application boots, and the Http facade has no root yet.
|
| A test that needs to talk to something must fake it. That is the point.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

pest()->beforeEach(fn () => Http::preventStrayRequests())->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
