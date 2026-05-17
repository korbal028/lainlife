<?php
declare(strict_types=1);
namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\RowModel;

class Conversation extends RowModel
{
    protected $tableName = "conversations";
    
    private function getRowData(): array
    {
        $id = $this->getId();
        $row = DatabaseConnection::i()->getContext()->table('conversations')->get($id);
        return $row ? (array) $row->toArray() : [];
    }

    public function save(?bool $log = false): void
{
    if (is_null($this->record)) {
        $table = DatabaseConnection::i()->getContext()->table($this->tableName);
        $this->record = $table->insert($this->changes);
        $this->changes = [];
    } else {
        parent::save($log);
    }
}

    public function getName(): string
    {
        return $this->getRowData()['name'] ?? '';
    }

    public function getOwner(): User
    {
        return (new Users())->get($this->getRowData()['owner_id']);
    }

    public function getAvatarUrl(): string
    {
        $data = $this->getRowData();
        if(!empty($data['avatar'])) {
            return $data['avatar'];
        }
        return "/assets/packages/static/openvk/img/camera_200.png";
    }

    public function getMembers(): array
    {
        $db = DatabaseConnection::i()->getContext();
        $rows = $db->table("conversation_members")
            ->where("conversation_id", $this->getId())
            ->where("left_at IS NULL")
            ->fetchAll();
        $members = [];
        foreach($rows as $row) {
            $user = (new Users())->get($row->user_id);
            if($user) $members[] = $user;
        }
        return $members;
    }

    public function isMember(User $user): bool
    {
        $db = DatabaseConnection::i()->getContext();
        return (bool) $db->table("conversation_members")
            ->where("conversation_id", $this->getId())
            ->where("user_id", $user->getId())
            ->where("left_at IS NULL")
            ->fetch();
    }

    public function addMember(User $user): void
    {
        $db = DatabaseConnection::i()->getContext();
        $existing = $db->table("conversation_members")
            ->where("conversation_id", $this->getId())
            ->where("user_id", $user->getId())
            ->fetch();
        if($existing) {
            $db->table("conversation_members")
                ->where("id", $existing->id)
                ->update(["left_at" => null]);
        } else {
            $db->table("conversation_members")->insert([
                "conversation_id" => $this->getId(),
                "user_id"         => $user->getId(),
                "joined"          => time(),
            ]);
        }
    }

    public function removeMember(User $user): void
    {
        $db = DatabaseConnection::i()->getContext();
        $db->table("conversation_members")
            ->where("conversation_id", $this->getId())
            ->where("user_id", $user->getId())
            ->update(["left_at" => time()]);
    }

    private function formatTime(int $timestamp): string
    {
        $now = time();
        $diff = $now - $timestamp;
        
        if ($diff < 60) {
            return 'только что';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' мин. назад';
        } elseif ($diff < 86400) {
            return date('H:i', $timestamp);
        } elseif ($diff < 172800) {
            return 'вчера в ' . date('H:i', $timestamp);
        } else {
            return date('d.m.Y в H:i', $timestamp);
        }
    }

    private function getAttachments(int $msgId): array
    {
        $db = DatabaseConnection::i()->getContext();
        $attachments = [];
        
        try {
            $rows = $db->table("conversation_attachments")
                ->where("message_id", $msgId)
                ->fetchAll();
                
            foreach ($rows as $row) {
                $type = $row->type;
                $objId = $row->object_id;
                
                $attachment = ['type' => $type];
                
                if ($type === 'photo') {
                    $photo = $db->table("photos")->get($objId);
                    if ($photo) {
                        $attachment['id'] = $photo->id;
                        $attachment['link'] = "/photo{$photo->owner_id}_{$photo->id}";
                        $attachment['photo'] = [
                            'url' => $photo->url ?? '',
                            'caption' => $photo->description ?? ''
                        ];
                    }
                } elseif ($type === 'video') {
                    $video = $db->table("videos")->get($objId);
                    if ($video) {
                        $attachment['id'] = $video->id;
                        $attachment['link'] = "/video{$video->owner_id}_{$video->id}";
                        $attachment['video'] = [
                            'name' => $video->name ?? '',
                            'url' => $video->url ?? '',
                            'author' => $video->author ?? '',
                            'formatted_length' => $video->length ? gmdate("i:s", $video->length) : '0:00'
                        ];
                    }
                } elseif ($type === 'audio') {
                    $audio = $db->table("audios")->get($objId);
                    if ($audio) {
                        $attachment['id'] = $audio->id;
                        $attachment['link'] = "/audio{$audio->owner_id}_{$audio->id}";
                        // html рендерится на клиенте или через шаблонизатор, тут упрощенно
                        $attachment['html'] = '<div class="audio-player" data-audio-id="'.$audio->id.'">'.$audio->name.'</div>';
                    }
                } elseif ($type === 'doc') {
                    $doc = $db->table("documents")->get($objId);
                    if ($doc) {
                        $attachment['id'] = $doc->id;
                        $attachment['link'] = "/doc{$doc->owner_id}_{$doc->id}";
                        $attachment['document'] = [
                            'name' => $doc->name ?? '',
                            'size_str' => $doc->size ? round($doc->size / 1024) . ' КБ' : '0 КБ',
                            'ext' => pathinfo($doc->name, PATHINFO_EXTENSION) ?? '',
                            'preview' => ['medium' => $doc->preview ?? '', 'tiny' => '']
                        ];
                    }
                } elseif ($type === 'note') {
                    $note = $db->table("notes")->get($objId);
                    if ($note) {
                        $attachment['id'] = $note->id;
                        $attachment['link'] = "/note{$note->owner_id}_{$note->id}";
                        $attachment['note'] = ['name' => $note->name ?? ''];
                    }
                }
                
                if (!empty($attachment['id'])) {
                    $attachments[] = $attachment;
                }
            }
        } catch (\Exception $e) {
            // Если таблица еще не создана
        }
        
        return $attachments;
    }

    public function getMessages(int $page = 1, ?int $before = null): array
    {
        $db = DatabaseConnection::i()->getContext();
        $query = $db->table("conversation_messages")
            ->where("conversation_id", $this->getId())
            ->where("deleted", 0)
            ->order("id ASC")
            ->limit(20);
        if($before) $query = $query->where("id < ?", $before);
        
        $msgs = $query->fetchAll();
        $result = [];
        
        foreach($msgs as $msg) {
            $sender = (new Users())->get($msg->sender_id);
            
            $forwarded = null;
            if (!empty($msg->forwarded_from)) {
                $origMsg = $db->table("conversation_messages")->get($msg->forwarded_from);
                if ($origMsg) {
                    $origSender = (new Users())->get($origMsg->sender_id);
                    $forwarded = [
                        "sender" => [
                            "id" => $origSender->getId(),
                            "link" => $origSender->getURL(),
                            "avatar" => $origSender->getAvatarUrl(),
                            "name" => $origSender->getFirstName()
                        ],
                        "text" => htmlspecialchars($origMsg->content)
                    ];
                }
            }
            
            $result[] = [
                "uuid"    => (int)$msg->id,
                "sender"  => [
                    "id"     => $sender->getId(),
                    "link"   => $sender->getURL(),
                    "avatar" => $sender->getAvatarUrl(),
                    "name"   => $sender->getFirstName(),
                ],
                "timing"  => [
                    "sent"   => $this->formatTime($msg->created),
                    "edited" => $msg->edited ? $this->formatTime($msg->edited) : null,
                ],
                "text"        => htmlspecialchars($msg->content),
                "read"        => true,
                "attachments" => $this->getAttachments($msg->id),
                "forwarded"   => $forwarded,
            ];
        }
        return $result;
    }

    public function sendMessage(User $sender, string $content, array $attachments = []): array
    {
        $db = DatabaseConnection::i()->getContext();
        $db->table("conversation_messages")->insert([
            "conversation_id" => $this->getId(),
            "sender_id"       => $sender->getId(),
            "content"         => $content,
            "created"         => time(),
        ]);
        $msgId = $db->getInsertId();
        
        foreach ($attachments as $attachment) {
            if (!$attachment) continue;
            $db->table("conversation_attachments")->insert([
                "message_id"  => $msgId,
                "type"        => $attachment->getType(),
                "object_id"   => $attachment->getId(),
            ]);
        }
        
        return [
            "uuid"    => (int)$msgId,
            "sender"  => [
                "id"     => $sender->getId(),
                "link"   => $sender->getURL(),
                "avatar" => $sender->getAvatarUrl(),
                "name"   => $sender->getFirstName(),
            ],
            "timing"  => [
                "sent"   => $this->formatTime(time()),
                "edited" => null,
            ],
            "text"        => htmlspecialchars($content),
            "read"        => true,
            "attachments" => $this->getAttachments($msgId),
            "forwarded"   => null,
        ];
    }
}
