<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\{AccountSecurity, Totp};
use App\Services\Billing\Billing;
use App\Services\Promotions\{Availability, Boosts, Promotion};

trait PromotionFixtures
{
    use CommunicationFixtures;

    protected function promotionSetup(): void
    {
        $this->app->mergeConfig(['promotions' => ['bookings_enabled' => true, 'surface_url' => 'https://web.example/discover',
            'pilot_monthly_capacity' => 4, 'feature_capacity' => 1,
            'network_accounts' => ['ezway-instagram' => ['name' => 'eZWay Instagram', 'monthly_capacity' => 4]],
            'proof_hosts' => ['www.instagram.com']]]);
        Availability::record(['available' => true, 'reference' => 'fixture-' . bin2hex(random_bytes(8)), 'note' => 'Controlled surface verification fixture']);
    }

    protected function promotionStaff(string $name = 'operator'): User
    {
        $staff = $this->makeUser($name);
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($staff, 'admin', $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        $this->asUser($staff)->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'staff', 'password' => 'password123',
            'mfa_code' => Totp::code($secret, intdiv(time(), 30))])->assertOk();

        return $staff;
    }

    protected function boostInput(int $video): array
    {
        return ['video_id' => $video, 'offer_id' => config('promotions.offer_id'), 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'rights_confirmed' => true, 'rights_version' => config('promotions.rights_version')];
    }

    protected function submittedBoost(User $owner): object
    {
        $video = $this->makeVideo($owner);
        $boost = Boosts::create($owner, $this->boostInput($video->id));
        Boosts::submit($owner, $boost->id, 1, 'fixture-submit-' . $boost->id);

        return Boosts::find($boost->id);
    }

    protected function activateBoost(User $owner, User $staff): object
    {
        $boost = $this->submittedBoost($owner);
        Boosts::decide($staff, $boost->id, ['version' => $boost->version, 'decision' => 'approve', 'reason' => 'Advertising rights and creative reviewed.']);
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => Billing::timestamp(time() - 1), 'ends_at' => Billing::timestamp(time() + 86399)]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));

        return Boosts::find($boost->id);
    }

    protected function deliveredWindow(object $boost, int $seconds): void
    {
        query('promotion_delivery')->insert(['boost_id' => $boost->id, 'starts_at' => Billing::timestamp(time() - $seconds), 'ends_at' => Billing::timestamp()]);
        query('promotion_boosts')->where('id', $boost->id)->update(['checked_at' => Billing::timestamp()]);
    }
}
