<?php declare(strict_types=1);
namespace openvk\Web\Models\Entities;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\Clubs;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\Repositories\GroupChats;
use openvk\Web\Models\Repositories\MessengerDialogs;
use openvk\Web\Models\Entities\{Photo, Video, Audio, Note, Document};
use openvk\Web\Models\Entities\Messages\Sticker;
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * Message entity.
 */
class Message extends RowModel
{
    protected $tableName = "messages";
    
    /**
     * Get message ID.
     *
     * @returns int
     */
    public function getId(): ?int
    {
        return $this->getRecord()->id;
    }
    /**
     * Get origin of the message.
     * 
     * Returns either user or club.
     * 
     * @returns User|Club
     */
    function getSender(): ?RowModel
    {
        if($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\User')
            return (new Users)->get($this->getRecord()->sender_id);
        else if($this->getRecord()->sender_type === 'openvk\Web\Models\Entities\Club')
            return (new Clubs)->get($this->getRecord()->sender_id);
    }
    
    /**
     * Get the destination of the message.
     * 
     * Returns user, club or group chat.
     * 
     * @returns User|Club|GroupChat
     */
    function getRecipient(): ?RowModel
    {
        if($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\User')
            return (new Users)->get($this->getRecord()->recipient_id);
        else if($this->getRecord()->recipient_type === 'openvk\Web\Models\Entities\Club')
            return (new Clubs)->get($this->getRecord()->recipient_id);
        else if($this->getRecord()->recipient_type === GroupChat::class)
            return (new GroupChats)->get((int) $this->getRecord()->recipient_id);

        return null;
    }

    function isInGroupChat(): bool
    {
        return $this->getRecord()->recipient_type === GroupChat::class;
    }
    
    function getUnreadState(): int
    {
        trigger_error("TODO: use isUnread", E_USER_DEPRECATED);
        
        return (int) $this->isUnread();
    }
    
    /**
     * Get date of initial publication.
     * 
     * @returns DateTime
     */
    function getSendTime(): DateTime
    {
        return new DateTime($this->getRecord()->created);
    }

    function getSendTimeHumanized(): string
    {
        $dateTime = new DateTime($this->getRecord()->created);

        if($dateTime->format("%d.%m.%y") == ovk_strftime_safe("%d.%m.%y", time())) {
            return $dateTime->format("%T");
        } else {
            return $dateTime->format("%d.%m.%y");
        }
    }
    
    /**
     * Get date of last edit, if any edits were made, otherwise null.
     * 
     * @returns DateTime|null
     */
    function getEditTime(): ?DateTime
    {
        $edited = $this->getRecord()->edited;
        if(is_null($edited)) return NULL;

        return new DateTime($edited);
    }

    function isDeleted(): bool
    {
        return (bool) $this->getRecord()->deleted;
    }


    public const MAX_FORWARDS      = 100;
    public const MAX_FORWARD_DEPTH = 10;

    public const REACTIONS = [
        "❤️", "⚡", "😁", "👍", "🤝", "💔", "🔥", "👀",
        "👎", "👏", "🤔", "😱", "😢", "🎉",
        "💩", "🙏", "👌", "🕊️", "🤡", "😍",
        "🐳", "🌚", "🌭", "🤣", "🍌",
        "😐", "🍓", "🍾", "💋", "🖕", "😈", "😴", "😭", "🤓",
        "😇", "😨", "🤢",
        "🎄", "☃️", "💅", "🗿", "💘",
        "😘", "💊", "😎", "👾", "🤷",
    ];

    /**
     * Reactions grouped by emoji, most popular first.
     *
     * @returns array
     */
    function getReactions(): array
    {
        $rows = DatabaseConnection::i()->getContext()->table("message_reactions")
            ->where("message", $this->getId())
            ->order("created ASC");

        $users  = new Users();
        $groups = [];
        foreach ($rows as $row) {
            $user = $users->get((int) $row->user);
            if (!$user) {
                continue;
            }

            $groups[(string) $row->reaction][] = [
                "id"     => $user->getId(),
                "name"   => $user->getCanonicalName(),
                "avatar" => $user->getAvatarUrl("miniscule"),
            ];
        }

        $reactions = [];
        foreach ($groups as $emoji => $reacted) {
            $reactions[] = ["emoji" => (string) $emoji, "users" => $reacted];
        }

        usort($reactions, fn($a, $b) => sizeof($b["users"]) <=> sizeof($a["users"]));
        return $reactions;
    }

    /**
     * Повторная та же реакция снимает её, другая — заменяет.
     */
    function toggleReaction(User $user, string $reaction): void
    {
        $context = DatabaseConnection::i()->getContext();
        $current = $context->table("message_reactions")->where(["message" => $this->getId(), "user" => $user->getId()])->fetchField("reaction");

        $context->table("message_reactions")->where(["message" => $this->getId(), "user" => $user->getId()])->delete();
        if ($current !== $reaction) {
            $context->table("message_reactions")->insert([
                "message"  => $this->getId(),
                "user"     => $user->getId(),
                "reaction" => $reaction,
                "created"  => time(),
            ]);
        }

        $context->query("UPDATE `messages` SET `reactions_changed` = ? WHERE `id` = ?", time(), $this->getId());
    }

    function getReplyTo(): ?Message
    {
        $id = $this->getRecord()->reply_to;
        if (is_null($id)) {
            return null;
        }

        return (new \openvk\Web\Models\Repositories\Messages())->get((int) $id);
    }

    /**
     * Forwarded messages in the order they were attached.
     *
     * @returns Message[]
     */
    function getForwardedMessages(): array
    {
        $repo = new \openvk\Web\Models\Repositories\Messages();
        $rels = DatabaseConnection::i()->getContext()->table("message_forwards")
            ->where("message", $this->getId())
            ->order("position ASC");

        $messages = [];
        foreach ($rels as $rel) {
            $msg = $repo->get((int) $rel->forwarded);
            if ($msg) {
                $messages[] = $msg;
            }
        }

        return $messages;
    }

    function setForwardedMessages(array $messages): void
    {
        $table = DatabaseConnection::i()->getContext()->table("message_forwards");
        foreach (array_values($messages) as $position => $msg) {
            $table->insert([
                "message"   => $this->getId(),
                "forwarded" => $msg->getId(),
                "position"  => $position,
            ]);
        }
    }

    function hasParticipant(RowModel $entity): bool
    {
        $rec = $this->getRecord();
        $me  = [get_class($entity), $entity->getId()];

        return [$rec->sender_type, (int) $rec->sender_id] === $me || [$rec->recipient_type, (int) $rec->recipient_id] === $me;
    }

    // видно ли сообщение пользователю: своё, личное ему или из беседы, где он состоит
    function isVisibleTo(User $user): bool
    {
        if ($this->hasParticipant($user)) {
            return true;
        }

        $chat = $this->isInGroupChat() ? $this->getRecipient() : null;

        return $chat && $chat->isParticipant($user->getId());
    }

    function isBetween(RowModel $a, RowModel $b): bool
    {
        return $this->hasParticipant($a) && $this->hasParticipant($b)
            && ($a->getId() !== $b->getId() || get_class($a) !== get_class($b) || $this->getRecord()->sender_id === $this->getRecord()->recipient_id);
    }

    function isForwarded(): bool
    {
        return DatabaseConnection::i()->getContext()->table("message_forwards")->where("message", $this->getId())->count("*") > 0;
    }

    /**
     * Is this message an ad?
     * 
     * Messages can never be ads.
     * 
     * @returns false
     */
    function isAd(): bool
    {
        return false;
    }
    
    // в беседе сообщение прочитано, если его прочитал хоть кто-то кроме отправителя
    function isUnread(): bool
    {
        if ($this->isInGroupChat()) {
            return sizeof($this->getReaderIds()) === 0;
        }

        return (bool) $this->getRecord()->unread;
    }

    // отметки прочтения бесед в рамках запроса, chat_id => отметки участников
    private static array $readMarks = [];

    public static function forgetReadMarks(): void
    {
        self::$readMarks = [];
    }

    /**
     * Участники беседы, кроме отправителя, дочитавшие до сообщения после вступления.
     *
     * @returns int[]
     */
    function getReaderIds(): array
    {
        if (!$this->isInGroupChat()) {
            return [];
        }

        $rec   = $this->getRecord();
        $chat  = (int) $rec->recipient_id;
        $marks = self::$readMarks[$chat] ??= (new MessengerDialogs())->getChatReadMarks($chat);

        $readers = [];
        foreach ($marks as $user => [$readTill, $joinedTill]) {
            if ($readTill >= $this->getId() && $joinedTill < $this->getId() && !($rec->sender_type === User::class && (int) $rec->sender_id === $user)) {
                $readers[] = $user;
            }
        }

        return $readers;
    }

    /**
     * @returns User[]
     */
    function getReaders(): array
    {
        return array_values(array_filter(array_map(fn($id) => (new Users())->get($id), $this->getReaderIds())));
    }
    
    /**
     * Simplify to array
     * 
     * @returns array
     */
    private function simplifySender(): array
    {
        $author = $this->getSender();

        return [
            "id"     => $author->getId(),
            "link"   => $author->getURL(),
            "avatar" => $author->getAvatarUrl("miniscule"),
            "name"   => $author->getCanonicalName(),
        ];
    }

    private function simplifyReply(): ?array
    {
        $reply = $this->getReplyTo();
        if (!$reply) {
            return null;
        }

        return [
            "uuid"    => $reply->getId(),
            "sender"  => $reply->simplifySender(),
            "ts"      => $reply->getSendTime()->timestamp(),
            "deleted" => $reply->isDeleted(),
            "text"    => $reply->isDeleted() ? "" : $reply->getPreviewText(),
        ];
    }

    function simplify(int $depth = 0): array
    {
        $author = $this->getSender();
        
        $attachments = [];
        foreach($this->getChildren() as $attachment) {
            if($attachment instanceof Photo) {
                $attachments[] = [
                    "type"  => "photo",
                    "link"  => "/photo" . $attachment->getPrettyId(),
                     "id"    => $attachment->getPrettyId(),
                    "photo" => [
                        "url"     => $attachment->getURL(),
                        "caption" => $attachment->getDescription(),
                    ],
                ];
            } elseif ($attachment instanceof Video) {
                $attachments[] = [
                    "type"  => "video",
                    "link"  => "/video" . $attachment->getPrettyId(),
                    "id"    => $attachment->getOwner()->getId() . "_" . $attachment->getVirtualId(),
                    "video" => [
                        "url"               => $attachment->getURL(),
                        "name"              => $attachment->getName(),
                        "length"            => $attachment->getLength(),
                        "formatted_length"  => $attachment->getFormattedLength(),
                        "thumbnail"         => $attachment->getThumbnailURL(),
                        "author"            => $attachment->getOwner()->getCanonicalName(),
                    ],
                ];
            } elseif ($attachment instanceof Audio) {
                $attachments[] = [
                    "type"  => "audio",
                    "link"  => "/audio" . $attachment->getPrettyId(),
                    "audio" => [
                        "name"   => $attachment->getName(),
                        "artist" => $attachment->getPerformer(),
                    ],
                ];
            } elseif ($attachment instanceof Note) {
                $attachments[] = [
                    "type"  => "note",
                    "link"  => "/note" . $attachment->getId(),
                    "id"    => $attachment->getId(),
                    "note"  => [
                        "name" => $attachment->getName(),
                    ],
                ];
            } elseif ($attachment instanceof Document) {
                $previewData = null;
                if ($attachment->hasPreview()) {
                    $previewData = [
                        "tiny" => $attachment->getPreview()->getURLBySizeId('tiny'),
                        "medium" => $attachment->getPreview()->getURLBySizeId('medium'),
                    ];
                }

                $attachments[] = [
                    "type"      => "doc",
                    "link"      => "/doc" . $attachment->getPrettyId(),
                    "id"        => $attachment->getPrettyId(),
                    "document"  => [
                        "name" => $attachment->getName(),
                        "ext"      => $attachment->getFileExtension(),
                        "size_str" => readable_filesize($attachment->getFilesize()),
                        "preview"  => $previewData,
                        "pub_time" => (string) $attachment->getPublicationTime(),
                    ],
                ];    
            } elseif ($attachment instanceof Sticker) {
                $attachments[] = $attachment->simplify();
            } else {
                $attachments[] = [
                    "type"  => "unknown"
                ];
            }
        }
        
        return [
            "uuid"   => $this->getId(),
            "sender" => [
                "id"     => $author->getId(),
                "link"   => $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . $author->getURL(),
                "avatar" => $author->getAvatarUrl(),
                "name"   => $depth > 0 ? $author->getCanonicalName() : $author->getFirstName(),
            ],
            "timing" => [
                "sent"   => (string) $this->getSendTimeHumanized(),
                "ts"     => $this->getSendTime()->timestamp(),
                "edited" => is_null($this->getEditTime()) ? NULL : (string) $this->getEditTime(),
            ],
            "text"        => $this->getText(),
            "reply"       => $depth === 0 ? $this->simplifyReply() : null,
            "fwd"         => $depth < self::MAX_FORWARD_DEPTH ? array_map(fn($m) => $m->simplify($depth + 1), $this->getForwardedMessages()) : [],
            "read"        => !$this->isUnread(),
            "attachments" => $attachments,
            "reactions"   => $depth === 0 ? $this->getReactions() : [],
        ];
    }
    
    use Traits\TRichText;
    use Traits\TAttachmentHost;
    use Traits\TMessagePreview;
}
