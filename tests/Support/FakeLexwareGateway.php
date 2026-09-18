<?php

namespace Tests\Support;

use App\Modules\UserProfile\Payment\Contracts\LexwareGateway;
use App\Modules\UserProfile\Payment\Data\LexwareContactRequest;
use App\Modules\UserProfile\Payment\Data\LexwareFile;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceRequest;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceResult;
use App\Modules\UserProfile\Payment\Data\LexwarePersonContactRequest;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;

class FakeLexwareGateway implements LexwareGateway
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var array<string, LexwareInvoiceResult> */
    public array $invoices = [];

    public string $voucherStatus = 'open';

    public ?string $voucherNumber = 'RE-';

    public ?string $nextContactId = null;

    public ?LexwareGatewayException $failCreateInvoice = null;

    public ?LexwareGatewayException $failRetrieveInvoice = null;

    public ?LexwareGatewayException $failFinalizeInvoice = null;

    public ?LexwareGatewayException $failDownload = null;

    public ?LexwareGatewayException $failCreateContact = null;

    private int $sequence = 0;

    public function createInvoice(LexwareInvoiceRequest $invoice): LexwareInvoiceResult
    {
        return $this->store($invoice, 'draft', 'createInvoice');
    }

    public function createFinalizedInvoice(LexwareInvoiceRequest $invoice): LexwareInvoiceResult
    {
        $this->record('createFinalizedInvoice', ['payload' => $invoice->toPayload()]);

        if ($this->failCreateInvoice !== null) {
            throw $this->consume($this->failCreateInvoice);
        }

        return $this->store($invoice, $this->voucherStatus, null);
    }

    public function retrieveInvoice(string $invoiceId): LexwareInvoiceResult
    {
        $this->record('retrieveInvoice', ['invoice_id' => $invoiceId]);

        if ($this->failRetrieveInvoice !== null) {
            throw $this->consume($this->failRetrieveInvoice);
        }

        return $this->invoices[$invoiceId] ?? throw LexwareGatewayException::apiError('Unknown invoice.', 404);
    }

    public function finalizeInvoice(string $invoiceId): LexwareInvoiceResult
    {
        $this->record('finalizeInvoice', ['invoice_id' => $invoiceId]);

        if ($this->failFinalizeInvoice !== null) {
            throw $this->consume($this->failFinalizeInvoice);
        }

        $existing = $this->invoices[$invoiceId] ?? throw LexwareGatewayException::apiError('Unknown invoice.', 404);

        if ($existing->isFinalized()) {
            return $existing;
        }

        $this->sequence++;

        // Mirrors the real thing: finalizing is where the number appears.
        return $this->invoices[$invoiceId] = new LexwareInvoiceResult(
            id: $existing->id,
            version: ($existing->version ?? 1) + 1,
            resourceUri: $existing->resourceUri,
            voucherStatus: $this->voucherStatus === 'draft' ? 'open' : $this->voucherStatus,
            voucherNumber: $this->voucherNumber === null ? null : $this->voucherNumber.$this->sequence,
        );
    }

    public function downloadInvoiceFile(string $invoiceId): LexwareFile
    {
        $this->record('downloadInvoiceFile', ['invoice_id' => $invoiceId]);

        if ($this->failDownload !== null) {
            throw $this->consume($this->failDownload);
        }

        return new LexwareFile('%PDF-1.4 '.$invoiceId, 'application/pdf', $invoiceId.'.pdf');
    }

    public function createCompanyContact(LexwareContactRequest $contact): string
    {
        $this->record('createCompanyContact', ['payload' => $contact->toPayload()]);

        return $this->newContactId();
    }

    public function createPersonContact(LexwarePersonContactRequest $contact): string
    {
        $this->record('createPersonContact', ['payload' => $contact->toPayload()]);

        if ($this->failCreateContact !== null) {
            throw $this->consume($this->failCreateContact);
        }

        return $this->newContactId();
    }

    public function listCustomerContacts(int $page = 0, int $size = 250): array
    {
        $this->record('listCustomerContacts', compact('page', 'size'));

        return [];
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_values(array_map(fn (array $call) => $call['method'], $this->calls));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastCall(string $method): ?array
    {
        $matching = array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));

        return $matching === [] ? null : end($matching);
    }

    private function store(LexwareInvoiceRequest $invoice, string $voucherStatus, ?string $method): LexwareInvoiceResult
    {
        if ($method !== null) {
            $this->record($method, ['payload' => $invoice->toPayload()]);
        }

        $this->sequence++;
        $id = 'invoice-'.$this->sequence;

        $this->invoices[$id] = new LexwareInvoiceResult(
            id: $id,
            version: 1,
            resourceUri: '/v1/invoices/'.$id,
            voucherStatus: $voucherStatus,
            voucherNumber: $voucherStatus === 'draft' || $this->voucherNumber === null ? null : $this->voucherNumber.$this->sequence,
            body: [],
        );

        return new LexwareInvoiceResult(
            id: $id,
            version: 1,
            resourceUri: '/v1/invoices/'.$id,
        );
    }

    private function newContactId(): string
    {
        $id = $this->nextContactId ?? 'contact-'.(count($this->calls) + 1);
        $this->nextContactId = null;

        return $id;
    }

    private function consume(LexwareGatewayException $exception): LexwareGatewayException
    {
        $this->failCreateInvoice = null;
        $this->failRetrieveInvoice = null;
        $this->failFinalizeInvoice = null;
        $this->failDownload = null;
        $this->failCreateContact = null;

        return $exception;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $method, array $context): void
    {
        $this->calls[] = ['method' => $method, ...$context];
    }
}
