<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities\Traits;

use openvk\Web\Models\Entities\{Photo, Video, Audio, Note, Document};
use openvk\Web\Models\Entities\Messages\Sticker;

trait TMessagePreview
{
    /**
     * Text to show in dialog/chat lists.
     *
     * Falls back to a label describing the attachment (or forwarded message)
     * when the message itself has no text, so previews aren't just a bare avatar.
     */
    public function getPreviewText(): string
    {
        $text = trim(strip_tags($this->getText()));
        if ($text !== "") {
            return $this->getText();
        }

        if ($this->isForwarded()) {
            return tr("msg_preview_forwarded");
        }

        foreach ($this->getChildren() as $attachment) {
            if ($attachment instanceof Photo) {
                return tr("msg_preview_photo");
            } elseif ($attachment instanceof Video) {
                return tr("msg_preview_video");
            } elseif ($attachment instanceof Audio) {
                return tr("msg_preview_audio");
            } elseif ($attachment instanceof Document) {
                return tr("msg_preview_document");
            } elseif ($attachment instanceof Note) {
                return tr("msg_preview_note");
            } elseif ($attachment instanceof Sticker) {
                return tr("msg_preview_sticker");
            } else {
                return tr("msg_preview_attachment");
            }
        }

        return $this->getText();
    }
}
