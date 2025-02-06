<?php

namespace DirectoryTree\ImapEngine\Connection\Data;

class GroupData extends Data
{
    /**
     * Get the group as a string.
     */
    public function __toString(): string
    {
        return sprintf('[%s]', implode(
            ' ', array_map('strval', $this->tokens)
        ));
    }
}
