<?php

namespace App\Notifications\Branch;

use App\Models\BranchInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BranchInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public BranchInvitation $invitation,
        #[\SensitiveParameter]
        private readonly string $plainToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->invitation->loadMissing(['branch', 'business', 'invitedBy']);

        $branchName = $this->invitation->branch?->name ?? 'a branch';
        $businessName = $this->invitation->business?->name ?? 'a partner business';
        $inviter = $this->invitation->invitedBy?->name ?? 'A Khana partner';
        $role = str_replace('_', ' ', $this->invitation->role);
        $expires = $this->invitation->expires_at?->toDayDateTimeString() ?? 'soon';

        $url = rtrim(config('suvakamana.frontend_url'), '/')
            .'/branch-invitations/accept?token='.urlencode($this->plainToken);

        return (new MailMessage)
            ->subject("You've been invited to manage {$branchName} on Khana")
            ->greeting('Hello'.($this->invitation->full_name ? ' '.$this->invitation->full_name : '').',')
            ->line("{$inviter} invited you to join {$businessName} as {$role} for {$branchName}.")
            ->line("This invitation expires on {$expires}.")
            ->action('Accept invitation', $url)
            ->line('You will create your own password. Nobody else can see it.')
            ->line('If you were not expecting this email, you can ignore it.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_public_id' => $this->invitation->public_id,
            'branch_id' => $this->invitation->branch_id,
            'business_id' => $this->invitation->business_id,
            'role' => $this->invitation->role,
        ];
    }
}
