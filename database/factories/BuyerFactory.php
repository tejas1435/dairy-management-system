<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Buyer;
use App\Models\SalesChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Buyer> */
class BuyerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'sales_channel_id' => SalesChannel::factory(),
            'name' => $this->faker->name(),
            'mobile' => $this->faker->numerify('9#########'),
            'email' => null,
            'address' => $this->faker->address(),
            'area' => $this->faker->city(),
            'payment_cycle' => 'monthly',
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function inChannel(SalesChannel $channel): static
    {
        return $this->state(fn (): array => [
            'sales_channel_id' => $channel->getKey(),
            'business_id' => $channel->business_id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A direct customer, resolving the seeded channel for the buyer's business.
     *
     * Requires the sales channels to have been seeded; tests use
     * `seedPhase2Masters()` or `seedDirectCustomerChannel()`.
     */
    public function directCustomer(?Business $business = null): static
    {
        return $this->state(function (array $attributes) use ($business): array {
            $businessId = $business?->getKey() ?? $attributes['business_id'] ?? null;

            $channel = SalesChannel::query()
                ->where('slug', SalesChannel::DIRECT_CUSTOMER)
                ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                ->firstOrFail();

            return [
                'sales_channel_id' => $channel->getKey(),
                'business_id' => $channel->business_id,
            ];
        });
    }

    /**
     * A Mandali, resolving the seeded channel for the buyer's business.
     *
     * Same shape as {@see directCustomer()} because a Mandali is the same kind of
     * thing: a buyer in a different channel (D26).
     */
    public function mandali(?Business $business = null): static
    {
        return $this->inSystemChannel(SalesChannel::MANDALI, $business);
    }

    /** A local dairy or vendor. */
    public function vendor(?Business $business = null): static
    {
        return $this->inSystemChannel(SalesChannel::VENDOR, $business);
    }

    /**
     * A buyer in an administrator-created channel — a hotel, a sweet shop.
     *
     * Creates the channel if it is not there, because a custom channel has no seeder
     * to rely on by definition.
     */
    public function inCustomChannel(?Business $business = null, string $slug = 'sweet_shop'): static
    {
        return $this->state(function (array $attributes) use ($business, $slug): array {
            $businessId = $business?->getKey() ?? $attributes['business_id'] ?? null;

            $channel = SalesChannel::query()
                ->where('slug', $slug)
                ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                ->first()
                ?? SalesChannel::factory()->create([
                    'business_id' => $businessId,
                    'slug' => $slug,
                    'name' => ucwords(str_replace('_', ' ', $slug)),
                    'is_system' => false,
                ]);

            return [
                'sales_channel_id' => $channel->getKey(),
                'business_id' => $channel->business_id,
            ];
        });
    }

    private function inSystemChannel(string $slug, ?Business $business): static
    {
        return $this->state(function (array $attributes) use ($slug, $business): array {
            $businessId = $business?->getKey() ?? $attributes['business_id'] ?? null;

            $channel = SalesChannel::query()
                ->where('slug', $slug)
                ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
                ->firstOrFail();

            return [
                'sales_channel_id' => $channel->getKey(),
                'business_id' => $channel->business_id,
            ];
        });
    }

    public function startingOn(?string $date): static
    {
        return $this->state(fn (): array => ['start_date' => $date]);
    }

    public function withDeliveryNote(string $note): static
    {
        return $this->state(fn (): array => ['delivery_note' => $note]);
    }

    public function inArea(string $area): static
    {
        return $this->state(fn (): array => ['area' => $area]);
    }
}
