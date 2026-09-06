<?php

namespace App\Services\Onboarding;

/**
 * A single step in the new-customer onboarding checklist: a stable key, the
 * human-facing title and description, whether it is complete, and the dashboard
 * route the Owner follows to complete it.
 */
class OnboardingStep
{
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly bool $complete,
        public readonly string $routeName,
        public readonly string $actionLabel,
    ) {}
}
