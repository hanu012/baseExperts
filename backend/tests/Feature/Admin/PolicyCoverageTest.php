<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Models\Commission;
use App\Models\Lead;
use App\Models\Setting;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Guards the fail-closed setup itself.
 *
 * Resource::checkPolicyExistence(false) means a model with no policy is
 * DENIED rather than allowed — which is the safe direction, but it turns a
 * missing policy into a resource nobody can open. These tests make that
 * failure arrive at commit time instead of in the panel.
 */
class PolicyCoverageTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    private function resourceClasses(): array
    {
        // Both panels: /super-admin resources fail closed the same way.
        return [
            ...Filament::getPanel('admin')->getResources(),
            ...Filament::getPanel('super-admin')->getResources(),
        ];
    }

    /**
     * Models gated by a policy but reached through a custom Filament
     * Page rather than a Resource — Pages have no getModel() for
     * resourceClasses()'s own matching loop to discover, so a
     * Page-gating permission (e.g. LeadsViewAny, LeadsAnalytics'
     * canAccess()) needs its model listed here explicitly or
     * test_every_permission_maps_to_a_real_policy_method() below
     * can't see it and reports a false positive: a permission that
     * looks orphaned when it's actually wired correctly.
     *
     * @return array<int, class-string>
     */
    private function additionalPolicySources(): array
    {
        return [Lead::class, Commission::class, Setting::class];
    }

    public function test_every_admin_resource_has_a_policy(): void
    {
        $missing = [];

        foreach ($this->resourceClasses() as $resource) {
            $model = $resource::getModel();

            if (Gate::getPolicyFor($model) === null) {
                $missing[] = class_basename($resource).' → '.class_basename($model);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Resources without a policy are unreachable now that authorization fails closed:\n  "
            .implode("\n  ", $missing)
        );
    }

    public function test_authorization_is_configured_to_fail_closed(): void
    {
        // If this ever flips back to true, a resource without a policy
        // becomes silently open to every sub-admin.
        $this->assertFalse(
            \Filament\Resources\Resource::shouldCheckPolicyExistence(),
            'checkPolicyExistence(false) must stay set, or missing policies fail open.'
        );
    }

    /**
     * Every permission in the enum must be checked by some policy, and every
     * module a policy names must appear in the enum — otherwise an ability is
     * grantable in the UI but enforces nothing, or vice versa.
     */
    public function test_every_permission_maps_to_a_real_policy_method(): void
    {
        $unmatched = [];

        $models = [
            ...array_map(fn (string $resource) => $resource::getModel(), $this->resourceClasses()),
            ...$this->additionalPolicySources(),
        ];

        foreach (Permission::cases() as $permission) {
            [$module, $action] = explode('.', $permission->value, 2);

            $matched = false;

            foreach ($models as $model) {
                $policy = Gate::getPolicyFor($model);

                if ($policy === null) {
                    continue;
                }

                // The policy resolves its own module; compare against it.
                $reflection = new \ReflectionClass($policy);

                if (! $reflection->hasMethod('module')) {
                    continue;
                }

                $moduleMethod = $reflection->getMethod('module');
                $moduleMethod->setAccessible(true);

                if ($moduleMethod->invoke($policy) === $module && method_exists($policy, $action)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $unmatched[] = $permission->value;
            }
        }

        $this->assertSame(
            [],
            $unmatched,
            'Permissions grantable in the UI but enforced by no policy: '.implode(', ', $unmatched)
        );
    }
}
