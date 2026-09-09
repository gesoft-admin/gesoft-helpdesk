<?php

namespace Modules\GesoftLiveChat\Console;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Console\Command;

/**
 * Create a chat conversation the way a widget eventually will, so the operator
 * side can be validated before any customer-facing surface exists.
 *
 * This is test tooling and is registered only when
 * `GESOFT_LIVE_CHAT_DEV_TOOLS=true`. It is an artisan command; no route
 * reaches it, and it is not a step in any customer flow.
 *
 *     php artisan gesoftlivechat:make-chat
 *     php artisan gesoftlivechat:make-chat --mailbox=1 --name="Ana Popescu"
 *
 * What it deliberately does *not* do is set anything by hand that core would
 * set itself. The realtime events, the folder counters and the chat list all
 * follow from `Thread::createExtended()` through `ThreadObserver` — if this
 * command had to poke them, the premise that core drives chat would be false
 * and the phase would have failed rather than passed.
 */
class MakeChatConversation extends Command
{
    protected $signature = 'gesoftlivechat:make-chat
                            {--mailbox= : Mailbox id; defaults to the first one}
                            {--name=Visitor : Display name for the customer}
                            {--messages=2 : How many customer messages to write}';

    protected $description = 'Create a TYPE_CHAT conversation with a customer who has no email (development only)';

    public function handle()
    {
        $channel = (int) config('gesoftlivechat.channel');

        $mailbox = $this->option('mailbox')
            ? Mailbox::find($this->option('mailbox'))
            : Mailbox::orderBy('id')->first();

        if (!$mailbox) {
            $this->error('No mailbox found. Create one first.');

            return 1;
        }

        // A visitor has no email and may never give one. This is the core
        // helper for exactly that case, and the reason the native channel is
        // viable at all: FreeScout does not insist on an address.
        $customer = Customer::createWithoutEmail([
            'first_name' => $this->option('name'),
        ]);

        // Ties the customer to our channel, which is what makes the "Web Chat"
        // tag appear on their profile. `channel_id` is the widget's opaque
        // visitor token in the real flow; here it is a stand-in.
        $customer->addChannel($channel, 'devtool-'.$customer->id);

        $body = 'Bună ziua, am o problemă cu aplicația și nu pot factura.';

        $result = Conversation::create(
            [
                'type'        => Conversation::TYPE_CHAT,
                'subject'     => Conversation::subjectFromText($body),
                'mailbox_id'  => $mailbox->id,
                'source_via'  => Conversation::PERSON_CUSTOMER,
                'source_type' => Conversation::SOURCE_TYPE_WEB,
                'state'       => Conversation::STATE_PUBLISHED,
                'channel'     => $channel,
            ],
            [[
                'type'     => Thread::TYPE_CUSTOMER,
                'body'     => $body,
                'state'    => Thread::STATE_PUBLISHED,
            ]],
            $customer
        );

        if (!$result) {
            $this->error('Conversation::create() returned false — no thread was created.');

            return 1;
        }

        $conversation = $result['conversation'];

        // `create()` leaves the status to the column default. The chat list
        // only shows active or pending conversations, so say it rather than
        // hope for it.
        if (!in_array($conversation->status, [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])) {
            $conversation->status = Conversation::STATUS_ACTIVE;
            $conversation->save();
        }

        $extra = max(0, (int) $this->option('messages') - 1);
        $follow_ups = [
            'Am încercat să repornesc, dar tot nu merge.',
            'Puteți intra pe calculatorul meu să vedeți?',
            'Aștept, mulțumesc.',
        ];

        for ($i = 0; $i < $extra; $i++) {
            Thread::createExtended(
                [
                    'type'  => Thread::TYPE_CUSTOMER,
                    'body'  => $follow_ups[$i % count($follow_ups)],
                    'state' => Thread::STATE_PUBLISHED,
                ],
                $conversation,
                $customer
            );
        }

        $conversation->refresh();

        $this->info('Created chat conversation:');
        $this->line('  conversation  #'.$conversation->number.'  (id '.$conversation->id.')');
        $this->line('  type          '.$conversation->type.'  ('.Conversation::typeToName($conversation->type).')');
        $this->line('  channel       '.$conversation->channel.'  ('.$conversation->getChannelName().')');
        $this->line('  customer      id '.$customer->id.', email "'.$customer->getMainEmail().'"');
        $this->line('  mailbox       '.$mailbox->name.'  (id '.$mailbox->id.')');
        $this->line('  threads       '.$conversation->threads_count);
        $this->line('  open it at    '.$conversation->url());

        return 0;
    }
}
