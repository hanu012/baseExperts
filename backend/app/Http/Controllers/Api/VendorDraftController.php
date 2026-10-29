<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StoreKycRequest;
use App\Http\Requests\Vendor\StoreVendorDraftRequest;
use App\Http\Resources\VendorResource;
use App\Http\Responses\ApiResponse;
use App\Models\Vendor;
use App\Services\AccountInviteService;
use App\Services\VendorDraftService;
use App\Support\ActiveSubscriptionSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorDraftController extends Controller
{
    public function __construct(private readonly VendorDraftService $drafts) {}

    /**
     * Creates a vendor in Draft (SPEC section 2.2).
     *
     * Saved before payment on purpose: salesmen work in the field on
     * unreliable networks and must be able to resume.
     *
     * RESUME BEHAVIOUR: a repeat submit that matches this salesman's own
     * existing draft returns that draft with `resumed: true` rather than a
     * duplicate error. If the response is lost after the row is written, a
     * retry has to recover the draft — otherwise the salesman is permanently
     * blocked on that vendor with no way forward from the field, which is
     * exactly what saving-before-payment exists to prevent.
     *
     * Anything else — a draft belonging to another salesman, or a vendor
     * further along its lifecycle — is a genuine duplicate and still rejects.
     */
    public function store(StoreVendorDraftRequest $request): JsonResponse
    {
        $data = $request->validated();
        $salesman = $request->user()->salesman;

        $existing = $this->drafts->findResumableDraft(
            $data['email'],
            $data['phone'],
            $salesman?->id,
        );

        if ($existing !== null) {
            return ApiResponse::success([
                'vendor' => new VendorResource($existing),
                'resumed' => true,
            ]);
        }

        $conflicts = $this->drafts->conflicts($data['email'], $data['phone']);

        if ($conflicts !== []) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => [
                    'code' => 'VENDOR_ALREADY_EXISTS',
                    'message' => 'A vendor with these details already exists.',
                    // Per-field so the app can mark the offending input rather
                    // than showing one generic banner.
                    'fields' => array_map(fn (string $m) => [$m], $conflicts),
                ],
            ], 422);
        }

        $vendor = $this->drafts->create($data, $salesman);

        return ApiResponse::success([
            'vendor' => new VendorResource($vendor),
            'resumed' => false,
        ], 201);
    }

    /**
     * Attaches KYC documents to an existing draft (SPEC section 2.2).
     *
     * A separate call from the create on purpose, matching SPEC's own
     * ordering: save the draft first, then upload. A slow or failed photo on
     * a weak connection then costs the upload, not the typed business
     * details.
     *
     * Files land on the local public disk for now - Cloudflare R2 is not
     * configured yet (see the Before Launch Checklist). The `disk` column
     * records where they actually went, so the eventual migration can move
     * them row by row.
     */
    public function storeKyc(StoreKycRequest $request, Vendor $vendor): JsonResponse
    {
        // Only for a vendor this salesman onboarded (admins: any), same as
        // sendPasswordLink — it used to accept any vendor id.
        $salesman = $request->user()->salesman;

        if ($salesman !== null && $vendor->created_by_salesman_id !== $salesman->id) {
            return ApiResponse::error('FORBIDDEN', 'You can only upload for a vendor you onboarded.', 403);
        }

        $disk = Vendor::currentUploadDisk();
        $changes = [];

        if ($request->hasFile('shop_photo')) {
            $changes['shop_photo_path'] = $request->file('shop_photo')
                ->store('vendor-kyc/'.$vendor->id, $disk);
        }

        if ($request->hasFile('id_proof')) {
            $changes['id_proof_path'] = $request->file('id_proof')
                ->store('vendor-kyc/'.$vendor->id, $disk);
        }

        if ($request->filled('id_proof_type')) {
            $changes['id_proof_type'] = $request->string('id_proof_type')->toString();
        }

        if ($changes !== []) {
            $vendor->fill($changes)->save();
        }

        return ApiResponse::success([
            'vendor' => new VendorResource($vendor->fresh()),
        ]);
    }

    /**
     * Lets the app recover a draft it has the id for after a reinstall or a
     * cleared cache, and backs the salesman's vendor detail screen.
     *
     * `active_subscription` is the same block `GET /vendors/me` returns, so
     * both screens read one shape and cannot disagree about a vendor's
     * quota. It is null for a draft or lapsed vendor — which is exactly the
     * "Not subscribed" state the salesman's list already shows.
     */
    /**
     * "Resend link" on the salesman's confirmation screen: emails the vendor
     * a new "set your password" link (Firebase sign-in only — see
     * AccountInviteService). For a vendor still on a password they did not
     * choose; one who has set their own uses "Forgot password?" instead.
     * A salesman may only do this for a vendor they onboarded.
     */
    public function sendPasswordLink(Request $request, Vendor $vendor, AccountInviteService $invites): JsonResponse
    {
        $salesman = $request->user()->salesman;

        if ($salesman !== null && $vendor->created_by_salesman_id !== $salesman->id) {
            return ApiResponse::error('FORBIDDEN', 'You can only send a password link to a vendor you onboarded.', 403);
        }

        $user = $vendor->user;

        if ($user === null || ! $invites->canInvite($user)) {
            return ApiResponse::error(
                'PASSWORD_LINK_UNAVAILABLE',
                $invites->enabled()
                    ? 'This vendor has already set their own password. They can use "Forgot password?" in the app.'
                    : 'Password links are not switched on.',
                422
            );
        }

        $sentTo = $invites->send($user);

        if ($sentTo === null) {
            return ApiResponse::error('PASSWORD_LINK_FAILED', 'The email could not be sent. Please try again in a moment.', 503);
        }

        return ApiResponse::success([
            'password_link_sent_to' => $sentTo,
            'message' => "Password link sent to {$sentTo}.",
        ]);
    }

    public function show(Request $request, Vendor $vendor): JsonResponse
    {
        return ApiResponse::success([
            'vendor' => new VendorResource($vendor->load('user')),
            'active_subscription' => ActiveSubscriptionSummary::for($vendor),
        ]);
    }
}
