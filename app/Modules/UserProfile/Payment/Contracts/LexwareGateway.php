<?php

namespace App\Modules\UserProfile\Payment\Contracts;

use App\Modules\UserProfile\Payment\Data\LexwareContactRequest;
use App\Modules\UserProfile\Payment\Data\LexwareFile;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceRequest;
use App\Modules\UserProfile\Payment\Data\LexwareInvoiceResult;
use App\Modules\UserProfile\Payment\Data\LexwarePersonContactRequest;
use App\Modules\UserProfile\Payment\Exceptions\LexwareGatewayException;

interface LexwareGateway
{
    /**
     * @throws LexwareGatewayException
     */
    public function createInvoice(LexwareInvoiceRequest $invoice): LexwareInvoiceResult;

    /**
     * @throws LexwareGatewayException
     */
    public function createFinalizedInvoice(LexwareInvoiceRequest $invoice): LexwareInvoiceResult;

    /**
     * @throws LexwareGatewayException
     */
    public function retrieveInvoice(string $invoiceId): LexwareInvoiceResult;

    /**
     * Returns the voucher, refusing while it is still a draft.
     *
     * Lexware vouchers are immutable through the API — `finalize` is a flag on
     * *creation*, and no endpoint promotes an existing draft — so finalizing
     * happens in Lexware's own UI, where §13 has accounting reviewing it. A
     * draft raises LexwareGatewayException::notFinalized(); only a finalized
     * voucher has an invoice number and a renderable document.
     *
     * @throws LexwareGatewayException
     */
    public function requireFinalizedInvoice(string $invoiceId): LexwareInvoiceResult;

    /**
     * @throws LexwareGatewayException
     */
    public function downloadInvoiceFile(string $invoiceId): LexwareFile;

    /**
     * @throws LexwareGatewayException
     */
    public function createCompanyContact(LexwareContactRequest $contact): string;

    /**
     * @throws LexwareGatewayException
     */
    public function createPersonContact(LexwarePersonContactRequest $contact): string;

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws LexwareGatewayException
     */
    public function listCustomerContacts(int $page = 0, int $size = 250): array;
}
