<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Http\Controllers\Concerns\HandlesServiceValidationErrors;
use App\Http\Requests\Onboarding\B2bRegistrationRequest;
use App\Models\User;
use App\Modules\UserProfile\B2B\Services\B2bContext;
use App\Modules\UserProfile\B2B\Services\B2BService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Post-registration onboarding for Firmenkunde (B2B) users: the company's
 * master data (name, USt-IdNr., address, logo) plus the LeasyBack admin
 * contact person, in one form — the same split leasyback_web's
 * RegisterCompanyView uses.
 *
 * The form is shown exactly once. From the moment the company exists its data
 * lives on "Mein Konto" (see Settings\ProfileController), which posts back to
 * `update()` through the `company.update` route — a company account has no
 * personal address/contact separate from its company's.
 *
 * Session-authenticated counterpart of the Sanctum API's B2BController:
 * a separate entry point over the same B2BService, exactly like
 * OnboardingController is to ProfileController for B2C. Nothing about a
 * company is derived from client-supplied ids here — the company is always
 * resolved from the authenticated user's own user_b2b row, so there is no
 * id to tamper with.
 */
class B2bRegistrationController extends Controller
{
    use HandlesServiceValidationErrors;

    /** Company logos are public assets, so they live on the public disk. */
    private const LOGO_DIRECTORY = 'logos/b2b';

    public function __construct(
        private readonly B2BService $b2bService,
        private readonly B2bContext $context,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->user_type !== UserType::Firmenkunde) {
            return to_route('dashboard');
        }

        // Registration is a one-time step. However the user came to belong to
        // a company — creating one here or accepting an invitation — the data
        // is theirs to review and edit on "Mein Konto", not on a second copy
        // of the registration form.
        if ($this->context->activeMembership($user) !== null) {
            return to_route('profile.edit');
        }

        return Inertia::render('onboarding/B2bRegistration');
    }

    public function store(B2bRegistrationRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Belonging to a company already — however they got there, including
        // by accepting an invitation — means there is nothing to register.
        if ($this->context->activeMembership($user) !== null) {
            return to_route('profile.edit');
        }

        $logo = $this->storeLogo($request->file('logo'), $user);

        $result = $this->withServiceErrorHandling(
            'company',
            fn () => $this->b2bService->create($user, $this->companyPayload($request->validated(), $logo))
        );

        if ($result !== null) {
            // The company row was never written, so the file it would have
            // pointed at must not linger on disk.
            $this->deleteLogo($logo['logo_path'] ?? null);

            return $result;
        }

        return to_route('dashboard')->with('success', 'Vielen Dank! Ihre Firmendaten wurden gespeichert.');
    }

    public function update(B2bRegistrationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $membership = $this->context->activeMembership($user);
        $company = $membership === null ? null : $this->companyFor($membership->b2bId);

        if (! $company) {
            abort(404);
        }

        $previousLogoPath = $company['logo_path'] ?? null;
        $logo = $this->resolveLogoUpdate($request, $user);

        $result = $this->withServiceErrorHandling(
            'company',
            fn () => $this->b2bService->update($user, $company['b2b'], $this->companyPayload($request->validated(), $logo))
        );

        if ($result !== null) {
            $this->deleteLogo($logo['logo_path'] ?? null);

            return $result;
        }

        // Replace, don't accumulate: only once the update committed is the
        // superseded file safe to remove.
        if ($logo !== [] && $previousLogoPath !== $logo['logo_path']) {
            $this->deleteLogo($previousLogoPath);
        }

        return to_route('profile.edit')->with('success', 'Firmendaten wurden aktualisiert.');
    }

    /**
     * Translate the Inertia form's shape into the payload B2BService speaks:
     * the first phone row is the company contact's primary number, any
     * further rows are additional numbers.
     *
     * @param  array<string, mixed>  $validated
     * @param  array{logo_url?: string|null, logo_path?: string|null}  $logo
     * @return array<string, mixed>
     */
    private function companyPayload(array $validated, array $logo): array
    {
        $phones = array_values($validated['phones']);
        $primary = array_shift($phones);

        return [
            'company_name' => $validated['company_name'],
            'vat_id' => $validated['vat_id'] ?? null,
            'contact_email' => $validated['contact_email'],
            'address' => [
                'street' => $validated['address']['street'],
                'number' => $validated['address']['number'],
                'additional_address' => $validated['address']['additional_address'] ?? null,
                'zip_code' => $validated['address']['zip_code'],
                'city' => $validated['address']['city'],
                'country' => $validated['address']['country'],
            ],
            'contact' => [
                'salutation' => $validated['contact']['salutation'],
                'first_name' => $validated['contact']['first_name'],
                'last_name' => $validated['contact']['last_name'],
                'international_prefix' => $primary['international_prefix'],
                'primary_phone_number' => $primary['phone_number'],
                'phone_numbers' => array_map(fn (array $phone) => [
                    'international_prefix' => $phone['international_prefix'],
                    'phone_number' => $phone['phone_number'],
                ], $phones),
            ],
            ...$logo,
        ];
    }

    /**
     * A new file replaces the logo, `remove_logo` clears it, and neither
     * leaves it untouched — an absent `logo` field must never be read as
     * "delete the logo the user already uploaded".
     *
     * @return array{logo_url?: string|null, logo_path?: string|null}
     */
    private function resolveLogoUpdate(B2bRegistrationRequest $request, User $user): array
    {
        if ($request->hasFile('logo')) {
            return $this->storeLogo($request->file('logo'), $user);
        }

        if ($request->boolean('remove_logo')) {
            return ['logo_url' => null, 'logo_path' => null];
        }

        return [];
    }

    /**
     * @return array{logo_url?: string|null, logo_path?: string|null}
     */
    private function storeLogo(?UploadedFile $file, User $user): array
    {
        if (! $file) {
            return [];
        }

        // The filename is generated, never taken from the upload: client
        // input decides no part of the path we write to.
        $path = self::LOGO_DIRECTORY."/{$user->id}-".Str::uuid().'.'.$file->extension();

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return [
            'logo_path' => $path,
            'logo_url' => Storage::disk('public')->url($path),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function companyFor(string $b2bId): ?array
    {
        return $this->b2bService->findById($b2bId);
    }

    private function deleteLogo(?string $path): void
    {
        if ($path !== null && $path !== '' && str_starts_with($path, self::LOGO_DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
