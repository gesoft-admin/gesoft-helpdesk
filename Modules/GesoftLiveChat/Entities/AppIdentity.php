<?php

namespace Modules\GesoftLiveChat\Entities;

use App\Customer;
use Illuminate\Database\Eloquent\Model;

/**
 * One application user, and the FreeScout customer they are.
 *
 * The key is `(provider, external_id)`, never the email address. An address
 * belongs to a mailbox, not to a person: it gets changed, it gets shared by a
 * department, and plenty of application accounts have none. Worse, an address
 * is guessable, and "resume the chat belonging to this address" is precisely
 * the hole that the per-conversation session token was introduced to close.
 *
 * So the address is written down when the customer is first created, because it
 * is how an agent recognises somebody they have emailed before — and after that
 * it decides nothing.
 */
class AppIdentity extends Model
{
    protected $table = 'gesoft_live_chat_app_identities';

    protected $guarded = ['id'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation()
    {
        return $this->belongsTo(\App\Conversation::class);
    }

    /**
     * The chat this identity is in, if there is one and it is still open.
     *
     * Checked against the customer as well as against its own status: a
     * conversation that an agent has moved to somebody else is no longer this
     * person's to resume, and the answer to "which chat am I in" then has to be
     * none rather than a conversation that happens to be named here.
     */
    public function openConversation()
    {
        $conversation = $this->conversation_id ? $this->conversation : null;

        if (!$conversation
            || $conversation->customer_id != $this->customer_id
            || $conversation->state != \App\Conversation::STATE_PUBLISHED
            || !in_array($conversation->status, [\App\Conversation::STATUS_ACTIVE, \App\Conversation::STATUS_PENDING])
        ) {
            return null;
        }

        return $conversation;
    }

    /**
     * The customer this application user is, creating both the customer and
     * the mapping the first time.
     *
     * `$email` and `$name` are what the application's server said, and they are
     * used only on the way in:
     *
     *  - a new identity whose address FreeScout already knows lands on that
     *    existing customer, so a chat joins the history of the tickets that
     *    person has already emailed about;
     *  - an identity that is already mapped is returned as it is. The
     *    application is not allowed to rename or re-address a customer on every
     *    page load: an agent may have corrected the record by hand, and a
     *    bootstrap must not undo that.
     *
     * The one exception is an address arriving for a customer who has none —
     * additive, and it is the difference between an agent being able to answer
     * by email afterwards and not.
     */
    public static function resolve($provider, $external_id, $name, $email)
    {
        $identity = self::where('provider', $provider)->where('external_id', $external_id)->first();

        if ($identity && ($customer = $identity->customer)) {
            if ($email && !$customer->getMainEmail()) {
                $customer->addEmail($email, true);
            }

            return [$customer, $identity, false];
        }

        $data = $name !== '' ? ['first_name' => $name] : [];

        $customer = $email ? Customer::create($email, $data) : null;
        if (!$customer) {
            $customer = Customer::createWithoutEmail($data);
        }

        // The mapping the identity is found by afterwards. The unique index is
        // the guard, not this select: two page loads racing each other both
        // reach here, and the one that loses the insert reads back the row the
        // winner wrote rather than failing. That row is then the truth -- the
        // customer created a moment ago on the losing side is left unused.
        try {
            $identity = self::create([
                'provider'    => $provider,
                'external_id' => $external_id,
                'customer_id' => $customer->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $identity = self::where('provider', $provider)->where('external_id', $external_id)->first();

            if (!$identity) {
                throw $e;
            }

            if ($identity->customer) {
                $customer = $identity->customer;
            }
        }

        return [$customer, $identity, true];
    }
}
