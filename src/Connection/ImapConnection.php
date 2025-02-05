<?php

namespace DirectoryTree\ImapEngine\Connection;

use DirectoryTree\ImapEngine\Exceptions\AuthFailedException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;
use DirectoryTree\ImapEngine\Imap;
use Illuminate\Support\Arr;
use Throwable;

class ImapConnection extends Connection
{
    /**
     * The current request sequence.
     */
    protected int $sequence = 0;

    /**
     * {@inheritDoc}
     */
    public function login(string $user, string $password): Response
    {
        try {
            return $this->requestAndResponse('LOGIN', $this->escapeString($user, $password), false);
        } catch (RuntimeException $e) {
            throw new AuthFailedException('Failed to authenticate', 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function authenticate(string $user, string $token): Response
    {
        try {
            $authenticateParams = ['XOAUTH2', base64_encode("user=$user\1auth=Bearer $token\1\1")];

            $response = $this->sendCommand('AUTHENTICATE', $authenticateParams);

            while (true) {
                $tokens = '';

                if ($this->readLine($response, $tokens, '+', false)) {
                    $response->addResponse($this->sendCommand(''));

                    continue;
                }

                if (preg_match('/^(NO|BAD) /i', $tokens)) {
                    return $response->addError("got failure response: $tokens");
                }

                if (preg_match('/^OK /i', $tokens)) {
                    return $response->setResult(is_array($tokens) ? $tokens : [$tokens]);
                }
            }
        } catch (RuntimeException $e) {
            throw new AuthFailedException('Failed to authenticate', 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function store(
        array|string $flags,
        int $from,
        ?int $to = null,
        ?string $mode = null,
        bool $silent = true,
        ?string $item = null
    ): Response {
        $flags = $this->escapeList(Arr::wrap($flags));

        $set = $this->buildSet($from, $to);

        $item = ($mode == '-' ? '-' : '+').(is_null($item) ? 'FLAGS' : $item).($silent ? '.SILENT' : '');

        $response = $this->requestAndResponse('UID STORE', [$set, $item, $flags], ! $silent);

        if ($silent) {
            return $response;
        }

        $result = [];

        foreach ($response->data() as $token) {
            if ($token[1] != 'FETCH' || $token[2][0] != 'FLAGS') {
                continue;
            }

            $result[$token[0]] = $token[2][1];
        }

        return $response->setResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function appendMessage(string $folder, string $message, ?array $flags = null, ?string $date = null): Response
    {
        $tokens = [];

        $tokens[] = $this->escapeString($folder);

        if ($flags !== null) {
            $tokens[] = $this->escapeList($flags);
        }

        if ($date !== null) {
            $tokens[] = $this->escapeString($date);
        }

        $tokens[] = $this->escapeString($message);

        return $this->requestAndResponse('APPEND', $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function copyMessage(string $folder, $from, ?int $to = null): Response
    {
        return $this->requestAndResponse('UID COPY', [
            $this->buildSet($from, $to),
            $this->escapeString($folder),
        ], false);
    }

    /**
     * {@inheritDoc}
     */
    public function copyManyMessages(array $messages, string $folder): Response
    {
        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse('UID COPY', $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function moveMessage(string $folder, $from, ?int $to = null): Response
    {
        $set = $this->buildSet($from, $to);

        return $this->requestAndResponse('UID MOVE', [$set, $this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function moveManyMessages(array $messages, string $folder): Response
    {
        $set = implode(',', $messages);

        $tokens = [$set, $this->escapeString($folder)];

        return $this->requestAndResponse('UID MOVE', $tokens, false);
    }

    /**
     * {@inheritDoc}
     */
    public function id(?array $ids = null): Response
    {
        $token = 'NIL';

        if (is_array($ids) && ! empty($ids)) {
            $token = '(';

            foreach ($ids as $id) {
                $token .= '"'.$id.'" ';
            }

            $token = rtrim($token).')';
        }

        return $this->requestAndResponse('ID', [$token], false);
    }

    /**
     * {@inheritDoc}
     */
    public function createFolder(string $folder): Response
    {
        return $this->requestAndResponse('CREATE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function renameFolder(string $oldPath, string $newPath): Response
    {
        return $this->requestAndResponse('RENAME', $this->escapeString($oldPath, $newPath), false);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteFolder(string $folder): Response
    {
        return $this->requestAndResponse('DELETE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function subscribeFolder(string $folder): Response
    {
        return $this->requestAndResponse('SUBSCRIBE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function unsubscribeFolder(string $folder): Response
    {
        return $this->requestAndResponse('UNSUBSCRIBE', [$this->escapeString($folder)], false);
    }

    /**
     * {@inheritDoc}
     */
    public function expunge(): Response
    {
        return $this->requestAndResponse('EXPUNGE');
    }

    /**
     * {@inheritDoc}
     */
    public function noop(): Response
    {
        return $this->requestAndResponse('NOOP');
    }

    /**
     * {@inheritDoc}
     */
    public function idle(): void
    {
        $response = $this->sendCommand('IDLE');

        while (true) {
            $line = $this->nextLine($response);

            // Server indicates it's ready for IDLE.
            if (str_starts_with($line, '+ ')) {
                return;
            }

            // Typical untagged or tagged "OK" lines.
            if (preg_match('/^\* /i', $line) || preg_match('/^TAG\d+ OK/i', $line)) {
                continue;
            }

            // Unexpected response.
            throw new RuntimeException('Idle failed. Unexpected response: '.trim($line));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function done(): void
    {
        $response = new Response($this->sequence, $this->debug);

        $this->write($response, 'DONE');

        while (true) {
            $line = $this->nextLine($response);

            // Typical tagged "OK" line.
            if (preg_match('/^TAG\d+ OK/i', $line)) {
                break;
            }

            // Handle untagged notifications (e.g. "* 4 EXISTS").
            if (preg_match('/^\* /i', $line)) {
                continue;
            }

            // Unexpected response.
            throw new RuntimeException('Done failed. Unexpected response: '.trim($line));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function search(array $params): Response
    {
        $response = $this->requestAndResponse('UID SEARCH', $params);

        $response->setCanBeEmpty(true);

        foreach ($response->data() as $ids) {
            if ($ids[0] === 'SEARCH') {
                array_shift($ids);

                return $response->setResult($ids);
            }
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function capability(): Response
    {
        $response = $this->requestAndResponse('CAPABILITY');

        if (! $response->getResponse()) {
            return $response;
        }

        return $response->setResult(
            $response->getValidatedData()[0]
        );
    }

    /**
     * Flatten/transform the tokens into the old array-based format if desired.
     * Otherwise, you can skip this and return $tokens directly.
     */
    protected function flattenTokens(array $tokens): array
    {
        $result = [];

        /** @var ImapToken $token */
        foreach ($tokens as $token) {
            if ($token->type() === ImapToken::TYPE_LIST) {
                // Recursively flatten sub-lists.
                $result[] = $this->flattenTokens($token->value());
            } else {
                // Just the raw token value.
                $result[] = $token->value();
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function logout(): Response
    {
        if (! $this->stream->isOpen() || ($this->meta()['timed_out'] ?? false)) {
            $this->reset();

            return new Response(0, $this->debug);
        }

        try {
            $result = $this->requestAndResponse('LOGOUT', [], false);

            $this->stream->close();
        } catch (Throwable) {
            $result = null;
        }

        $this->reset();

        return $result ?? new Response(0, $this->debug);
    }

    /**
     * {@inheritDoc}
     */
    public function selectFolder(string $folder = 'INBOX'): Response
    {
        return $this->examineOrSelect('SELECT', $folder);
    }

    /**
     * {@inheritDoc}
     */
    public function examineFolder(string $folder = 'INBOX'): Response
    {
        return $this->examineOrSelect('EXAMINE', $folder);
    }

    /**
     * Examine and select have the same response.
     *
     * @param  string  $command  can be 'EXAMINE' or 'SELECT'
     * @param  string  $folder  target folder
     */
    protected function examineOrSelect(string $command = 'EXAMINE', string $folder = 'INBOX'): Response
    {
        $response = $this->sendCommand($command, [$this->escapeString($folder)], $tag);

        $result = [];
        $tokens = [];

        while (! $this->readLine($response, $tokens, $tag)) {
            if ($tokens[0] == 'FLAGS') {
                array_shift($tokens);

                $result['flags'] = $tokens;

                continue;
            }

            switch ($tokens[1]) {
                case 'EXISTS':
                case 'RECENT':
                    $result[strtolower($tokens[1])] = (int) $tokens[0];
                    break;
                case '[UIDVALIDITY':
                    $result['uidvalidity'] = (int) $tokens[2];
                    break;
                case '[UIDNEXT':
                    $result['uidnext'] = (int) $tokens[2];
                    break;
                case '[UNSEEN':
                    $result['unseen'] = (int) $tokens[2];
                    break;
                case '[NONEXISTENT]':
                    throw new RuntimeException("Folder doesn't exist");
                default:
                    // ignore
                    break;
            }
        }

        $response->setResult($result);

        if ($tokens[0] != 'OK') {
            $response->addError('request failed');
        }

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function folderStatus(string $folder = 'INBOX', array $arguments = ['MESSAGES', 'UNSEEN', 'RECENT', 'UIDNEXT', 'UIDVALIDITY']): Response
    {
        $response = $this->requestAndResponse('STATUS', [
            $this->escapeString($folder),
            $this->escapeList($arguments),
        ]);

        $data = $response->getValidatedData();

        if (! isset($data[0]) || ! isset($data[0][2])) {
            throw new RuntimeException('Folder status could not be fetched');
        }

        $result = [];

        $key = null;

        foreach ($data[0][2] as $value) {
            if (is_null($key)) {
                $key = $value;
            } else {
                $result[strtolower($key)] = (int) $value;
                $key = null;
            }
        }

        $response->setResult($result);

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function uids(int|array $msgns): Response
    {
        return $this->fetch(['UID'], Arr::wrap($msgns), null, Imap::ST_MSGN);
    }

    /**
     * {@inheritDoc}
     */
    public function contents(int|array $ids): Response
    {
        return $this->fetch(['BODY[TEXT]'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function headers(int|array $ids): Response
    {
        return $this->fetch(['BODY[HEADER]'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function flags(int|array $ids): Response
    {
        return $this->fetch(['FLAGS'], Arr::wrap($ids));
    }

    /**
     * {@inheritDoc}
     */
    public function sizes(int|array $ids): Response
    {
        return $this->fetch(['RFC822.SIZE'], Arr::wrap($ids));
    }

    /**
     * Fetch one or more items of one or more messages.
     */
    public function fetch(array|string $items, array|int $from, mixed $to = null, $identifier = Imap::ST_UID): Response
    {
        if (is_array($from) && count($from) > 1) {
            $set = implode(',', $from);
        } elseif (is_array($from) && count($from) === 1) {
            $set = $from[0].':'.$from[0];
        } elseif (is_null($to)) {
            $set = $from.':'.$from;
        } elseif ($to == INF) {
            $set = $from.':*';
        } else {
            $set = $from.':'.(int) $to;
        }

        $items = (array) $items;

        $prefix = match ($identifier) {
            Imap::ST_UID => 'UID',
            default => '',
        };

        $response = $this->sendCommand(
            trim($prefix.' FETCH'),
            [$set, $this->escapeList($items)],
            $tag
        );

        $result = [];
        $tokens = [];

        while (! $this->readLine($response, $tokens, $tag)) {
            if (! isset($tokens[1])) {
                continue;
            }

            // Ignore other responses.
            if ($tokens[1] != 'FETCH') {
                continue;
            }

            $uidKey = 0;
            $data = [];

            // Find array key of UID value; try the last elements, or search for it.
            if ($identifier === Imap::ST_UID) {
                $count = count($tokens[2]);

                if ($tokens[2][$count - 2] == 'UID') {
                    $uidKey = $count - 1;
                } elseif ($tokens[2][0] == 'UID') {
                    $uidKey = 1;
                } else {
                    $found = array_search('UID', $tokens[2]);

                    if ($found === false || $found === -1) {
                        continue;
                    }

                    $uidKey = $found + 1;
                }
            }

            // Ignore other messages.
            if (is_null($to) && ! is_array($from) && ($identifier === Imap::ST_UID ? $tokens[2][$uidKey] != $from : $tokens[0] != $from)) {
                continue;
            }

            // If we only want one item we return that one directly.
            if (count($items) == 1) {
                if ($tokens[2][0] == $items[0]) {
                    $data = $tokens[2][1];
                } elseif ($identifier === Imap::ST_UID && $tokens[2][2] == $items[0]) {
                    $data = $tokens[2][3];
                } else {
                    $expectedResponse = 0;

                    // Maybe the server send another field we didn't wanted.
                    $count = count($tokens[2]);

                    // We start with 2, because 0 was already checked.
                    for ($i = 2; $i < $count; $i += 2) {
                        if ($tokens[2][$i] != $items[0]) {
                            continue;
                        }

                        $data = $tokens[2][$i + 1];

                        $expectedResponse = 1;

                        break;
                    }

                    if (! $expectedResponse) {
                        continue;
                    }
                }
            } else {
                while (key($tokens[2]) !== null) {
                    $data[current($tokens[2])] = next($tokens[2]);

                    next($tokens[2]);
                }
            }

            // If we want only one message we can ignore everything else and just return.
            if (is_null($to) && ! is_array($from) && ($identifier === Imap::ST_UID ? $tokens[2][$uidKey] == $from : $tokens[0] == $from)) {
                // We still need to read all lines.
                if (! $this->readLine($response, $tokens, $tag)) {
                    return $response->setResult($data);
                }
            }

            if ($identifier === Imap::ST_UID) {
                $result[$tokens[2][$uidKey]] = $data;
            } else {
                $result[$tokens[0]] = $data;
            }
        }

        if (is_null($to) && ! is_array($from)) {
            throw new RuntimeException('The single id was not found in response');
        }

        return $response->setResult($result);
    }

    /**
     * {@inheritDoc}
     */
    public function folders(string $reference = '', string $folder = '*'): Response
    {
        $response = $this->requestAndResponse('LIST', $this->escapeString($reference, $folder));

        $response->setCanBeEmpty(true);

        $list = $response->data();

        $result = [];

        if ($list[0] !== true) {
            foreach ($list as $item) {
                if (count($item) != 4 || $item[0] != 'LIST') {
                    continue;
                }

                $item[3] = str_replace('\\\\', '\\', str_replace('\\"', '"', $item[3]));

                $result[$item[3]] = [
                    'delimiter' => $item[2],
                    'flags' => $item[1],
                ];
            }
        }

        return $response->setResult($result);
    }

    /**
     * Build a UID number set.
     */
    public function buildSet($from, $to = null): int|string
    {
        $set = (int) $from;

        if ($to !== null) {
            $set .= ':'.($to == INF ? '*' : (int) $to);
        }

        return $set;
    }
}
