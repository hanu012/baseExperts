<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\StorePortfolioMediaRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Media;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vendor portfolio media (SPEC section 3 item 5, task 4.5) — photos/videos
 * of completed work, uploaded to local disk via TracksFileDisk (R2 isn't
 * configured yet, same standing note as vendor KYC), quota-capped by the
 * active subscription's plan.
 *
 * AUTO-APPROVED: an upload is live for customers the moment it is saved
 * (moderation_status=approved). There is no review step any more; an admin
 * can still take a file down afterwards with Reject on the vendor's Media
 * tab, which also frees its quota slot.
 *
 * Split out from VendorController: portfolio is a distinct enough concern
 * from me()/addServices() to warrant its own file, matching how
 * VendorDraftController/SubscriptionController are already split by
 * concern rather than grown into one controller.
 */
class VendorPortfolioController extends Controller
{
    /**
     * The vendor's own uploads plus current photo/video quota — the read
     * companion to store() below, without which the Flutter screen would
     * be an upload button with no gallery or quota display.
     */
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        if ($vendor === null) {
            return ApiResponse::error('NOT_FOUND', 'No vendor profile exists for this account.', 404);
        }

        $media = $vendor->media()
            ->with('subcategory')
            ->latest('created_at')
            ->get();

        return ApiResponse::success([
            'media' => $media->map(fn (Media $item) => $this->mediaResource($item))->all(),
            'quota' => $this->quotaSummary($vendor),
        ]);
    }

    /**
     * Uploads a single photo or video within remaining quota
     * (StorePortfolioMediaRequest does the resolving/validating; this just
     * writes the file and the row).
     */
    public function store(StorePortfolioMediaRequest $request): JsonResponse
    {
        $vendor = $request->user()->vendor;
        $disk = Vendor::currentUploadDisk();
        $type = $request->string('type')->toString();

        $path = $request->file('file')->store('vendor-portfolio/'.$vendor->id, $disk);

        $media = $vendor->media()->create([
            'subcategory_id' => $request->integer('subcategory_id'),
            'disk' => $disk,
            'path' => $path,
            'type' => $type,
            'size_bytes' => $request->file('file')->getSize(),
            // Live immediately — see the class docblock.
            'moderation_status' => 'approved',
            'moderated_at' => now(),
        ]);

        return ApiResponse::success([
            'media' => $this->mediaResource($media),
            'quota' => $this->quotaSummary($vendor->fresh()),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaResource(Media $media): array
    {
        return [
            'id' => $media->id,
            'type' => $media->type,
            'url' => $media->fileUrl(),
            'subcategory_id' => $media->subcategory_id,
            'subcategory_name' => $media->subcategory?->name,
            'moderation_status' => $media->moderation_status,
            'rejection_reason' => $media->rejection_reason,
            'created_at' => $media->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotaSummary(Vendor $vendor): array
    {
        $subscription = $vendor->currentActiveSubscription();

        if ($subscription === null || $subscription->plan->quota === null) {
            return ['photos' => null, 'videos' => null];
        }

        $counts = Media::query()
            ->where('mediable_type', $vendor->getMorphClass())
            ->where('mediable_id', $vendor->id)
            ->where('moderation_status', '!=', 'rejected')
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        // effectiveQuota() (task 4.7) folds in any purchased add-on
        // quantity on top of the bare plan limit.
        return [
            'photos' => ['used' => (int) ($counts['image'] ?? 0), 'max' => $subscription->effectiveQuota('photos')],
            'videos' => ['used' => (int) ($counts['video'] ?? 0), 'max' => $subscription->effectiveQuota('videos')],
        ];
    }
}
