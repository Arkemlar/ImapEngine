<?php

namespace DirectoryTree\ImapEngine\Connection\Responses;

use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

class TaggedResponse extends Response implements StatusResponse
{
    use HasStatus;

    /**
     * Get the response tag.
     */
    public function tag(): Atom
    {
        return $this->tokens[0];
    }

    /**
     * Get the response status token.
     */
    public function status(): Atom
    {
        return $this->tokens[1];
    }

    /**
     * Get the response messages.
     *
     * @return Atom[]
     */
    public function messages(): array
    {
        return array_slice($this->tokens, 2);
    }
}
