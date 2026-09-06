<?php

namespace App\Services\Onboarding;

/**
 * The computed onboarding checklist for a Company: the ordered steps plus the
 * derived progress readouts the dashboard renders. Immutable — produced by
 * {@see OnboardingChecklist::for()}.
 */
class OnboardingProgress
{
    /**
     * @param  list<OnboardingStep>  $steps
     */
    public function __construct(private readonly array $steps) {}

    /**
     * The ordered onboarding steps.
     *
     * @return list<OnboardingStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Whether every step is complete. When true the dashboard hides the
     * checklist.
     */
    public function isComplete(): bool
    {
        foreach ($this->steps as $step) {
            if (! $step->complete) {
                return false;
            }
        }

        return $this->steps !== [];
    }

    /**
     * How many steps are complete (for the "2 of 4" progress readout).
     */
    public function completedCount(): int
    {
        return count(array_filter($this->steps, static fn (OnboardingStep $s): bool => $s->complete));
    }

    /**
     * Total number of steps.
     */
    public function totalCount(): int
    {
        return count($this->steps);
    }
}
