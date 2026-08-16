<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlatformLead;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Acknowledgement for a public firm registration request.
 *
 * This is NOT FirmOwnerInvitationNotification and must never become it. The
 * invitation carries a signed password-setup token and is sent only once
 * FirmProvisioningService has actually created the Firm. This message is sent
 * the moment a stranger submits a form, so it deliberately carries no token, no
 * link into the application, and no claim that anything exists yet.
 *
 * The wording matters as much as the code: at this point no Firm, User or
 * FirmUser has been created and no access has been granted. Saying anything
 * warmer than "received, pending review" would be untrue, and a recipient who
 * believes they have an account will not act on the real invitation later.
 */
class FirmRegistrationReceivedNotification extends Notification
{
    public function __construct(
        private readonly PlatformLead $lead,
        private readonly ?string $correlationId = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = trim((string) strtok((string) $this->lead->contact_name, ' ')) ?: 'there';

        $message = (new MailMessage)
            ->subject('FirmsVault — Registration received')
            ->greeting('Hello '.$firstName.',')
            ->line("We've received the registration request for ".$this->lead->company_name.'.')
            ->line('Your request is pending review. Once the firm is verified and provisioned, '
                ."you'll receive a separate secure invitation to finish setting up your FirmsVault account.")
            ->line('No account access has been granted yet.')
            ->salutation('FirmsVault');

        // Same mechanism FirmOwnerInvitationNotification uses: the correlation
        // id becomes an SES message tag so a send can be traced back to its
        // correlation row without putting anything sensitive in the body.
        if ($this->correlationId !== null) {
            $message->metadata('correlation_id', $this->correlationId);
        }

        return $message;
    }
}
