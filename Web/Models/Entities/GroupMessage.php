<?php declare(strict_types=1);
namespace openvk\Web\Models\Entities;
use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\{Users, Clubs, GroupChats};
use openvk\Web\Models\Entities\{Photo, Video, Audio, Note, Document};
use openvk\Web\Models\RowModel;
use openvk\Web\Util\DateTime;

/**
 * GroupMessage entity.
 */
class GroupMessage extends RowModel
{
    protected $tableName = "group_messages";

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
     * Get the group chat this message belongs to.
     *
     * @returns GroupChat|null
     */
    function getGroupChat(): ?GroupChat
    {
        return (new GroupChats())->get($this->getRecord()->chat_id);
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

    function getForwardedMessage(): ?GroupMessage
    {
        $fwd = $this->getRecord()->forwarded_from;
        if(is_null($fwd)) return NULL;
        return (new \openvk\Web\Models\Repositories\GroupMessages())->get($fwd);
    }

    function isForwarded(): bool
    {
        return !is_null($this->getRecord()->forwarded_from);
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

    function isUnread(): bool
    {
        return (bool) $this->getRecord()->unread;
    }

    /**
     * Simplify to array
     *
     * @returns array
     */
    function simplify(): array
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
                "name"   => $author->getFirstName(),
            ],
            "chat_id" => $this->getRecord()->chat_id,
            "timing" => [
                "sent"   => (string) $this->getSendTimeHumanized(),
                "edited" => is_null($this->getEditTime()) ? NULL : (string) $this->getEditTime(),
            ],
            "text"        => $this->getText(),
            "forwarded"   => $this->isForwarded() ? $this->getForwardedMessage()?->simplify() : null,
            "read"        => !$this->isUnread(),
            "attachments" => $attachments,
        ];
    }

    use Traits\TRichText;
    use Traits\TAttachmentHost;
}
