<?php

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Domain services in this app report failures two ways: throwing
 * HttpResponseException with a plain {"error": "..."} JSON body (e.g.
 * ProfileService, VehicleService::fail()), or a plain abort($status, $message)
 * (e.g. VehicleService::createVehicle()'s admin-input-validation branches).
 * Both are correct for the Sanctum API controllers, but Inertia doesn't know
 * how to render either mid-visit. This bridges both: run the service call,
 * and on failure redirect back with the message flashed into the named
 * error-bag field, exactly like a normal validation error.
 */
trait HandlesServiceValidationErrors
{
    private function withServiceErrorHandling(string $errorField, Closure $callback): ?RedirectResponse
    {
        try {
            $callback();
        } catch (HttpResponseException $e) {
            $message = $e->getResponse()->getData(true)['error'] ?? 'Something went wrong. Please try again.';

            return back()->withErrors([$errorField => $message]);
        } catch (HttpExceptionInterface $e) {
            return back()->withErrors([$errorField => $e->getMessage() ?: 'Something went wrong. Please try again.']);
        }

        return null;
    }
}
