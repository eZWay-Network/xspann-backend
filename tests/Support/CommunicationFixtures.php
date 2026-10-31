<?php

namespace Tests\Support;

use App\Models\{Follow, User};
use App\Services\{Announcements, Communications, Conversations};

trait CommunicationFixtures
{
    use ArtistFixtures, BillingFixtures;

    protected function mutual(User $a, User $b): void
    {
        Follow::create(['follower_id' => $a->id, 'following_id' => $b->id]);
        Follow::create(['follower_id' => $b->id, 'following_id' => $a->id]);
    }

    protected function messageInput(string $body = 'Private hello'): array
    {
        return ['body' => $body, 'client_message_id' => sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2))[0] . bin2hex(random_bytes(1)), bin2hex(random_bytes(2))[0] . bin2hex(random_bytes(1)), bin2hex(random_bytes(6)))];
    }

    protected function paidArtist(): array
    {
        $this->billingSetup();
        $owner = $this->makeUser('artist');
        $artist = $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);

        return [$owner, $artist];
    }

    protected function subscriber(int $artistId, string $username = 'fan'): User
    {
        $fan = $this->makeUser($username);
        Communications::subscribe($fan, $artistId, true, config('communications.consent_version'));

        return $fan;
    }

    protected function campaign(User $owner, string $key = 'announcement-test'): object
    {
        $row = Announcements::create($owner, ['title' => 'New release', 'body' => 'Listen to my latest original song']);
        Announcements::send($owner, $row->id, 1, $key);

        return $row;
    }
}
