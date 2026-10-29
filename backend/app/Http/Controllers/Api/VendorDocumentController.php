<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreVendorDocumentRequest;
use App\Http\Resources\VendorDocumentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Services\VendorKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KYC documents from the apps (VendorKycService):
 *
 *  - the vendor's own — GET/POST vendors/me/documents. Open while the
 *    account is pending approval (RequireApprovedAccount), since uploading
 *    them is what the pending vendor is waiting to do;
 *  - a salesman's — GET/POST vendors/{vendor}/documents, only for a vendor
 *    they onboarded (admins: any vendor).
 *
 * Both answer with the whole list, so the app redraws from one response.
 * Verifying is admin-panel only.
 */
class VendorDocumentController extends Controller
{
    public function __construct(private readonly VendorKycService $kyc) {}

    public function indexMine(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        return $vendor === null
            ? ApiResponse::error('NOT_FOUND', 'No vendor profile for this account.', 404)
            : $this->list($vendor);
    }

    public function storeMine(StoreVendorDocumentRequest $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        return $vendor === null
            ? ApiResponse::error('NOT_FOUND', 'No vendor profile for this account.', 404)
            : $this->upload($request, $vendor);
    }

    public function index(Request $request, Vendor $vendor): JsonResponse
    {
        return $this->forbidden($request, $vendor) ?? $this->list($vendor);
    }

    public function store(StoreVendorDocumentRequest $request, Vendor $vendor): JsonResponse
    {
        return $this->forbidden($request, $vendor) ?? $this->upload($request, $vendor);
    }

    /** A salesman only reaches vendors they onboarded. */
    private function forbidden(Request $request, Vendor $vendor): ?JsonResponse
    {
        $salesman = $request->user()->salesman;

        return $salesman !== null && $vendor->created_by_salesman_id !== $salesman->id
            ? ApiResponse::error('FORBIDDEN', 'You can only manage documents for a vendor you onboarded.', 403)
            : null;
    }

    private function upload(StoreVendorDocumentRequest $request, Vendor $vendor): JsonResponse
    {
        $type = $request->documentType();
        $existing = $vendor->documents()->where('document_type_id', $type->id)->first();

        if ($existing !== null && ! $existing->isUploadable()) {
            return ApiResponse::error(
                'DOCUMENT_LOCKED',
                $existing->status === VendorDocument::VERIFIED
                    ? 'This document is already verified.'
                    : 'This document is being checked. You can upload again if it is sent back.',
                409
            );
        }

        $this->kyc->recordUpload($vendor, $type, [
            'number' => $request->input('number'),
            'expiry_date' => $request->input('expiry_date'),
            'front_path' => $this->kyc->storeFile($vendor, $request->file('front')),
            'back_path' => $request->hasFile('back') ? $this->kyc->storeFile($vendor, $request->file('back')) : null,
        ], $request->user());

        return $this->list($vendor);
    }

    private function list(Vendor $vendor): JsonResponse
    {
        $missing = $this->kyc->missingCompulsory($vendor);

        return ApiResponse::success([
            'documents' => VendorDocumentResource::collection($this->kyc->documentsFor($vendor)),
            'missing_compulsory' => $missing,
            'complete' => $missing === [],
        ]);
    }
}
