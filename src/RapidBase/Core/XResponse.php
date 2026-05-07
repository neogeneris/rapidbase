<?php

namespace RapidBase\Core;

class XResponse implements \JsonSerializable
{
    public readonly array $data;
    public readonly string $sql;
    public readonly float $durationMs;

    public readonly int $total;
    public readonly int $page;
    public readonly int $lastPage;
    public readonly int $limit;
    public readonly ?int $nextPage;
    public readonly ?int $prevPage;

    public readonly array $columns;
    public readonly array $titles;

    public readonly bool $success;
    public readonly int $affected;
    public readonly int|string|null $lastId;

    public function __construct(
        array $data,
        string $sql,
        float $durationMs,
        int $total = 0,
        int $page = 1,
        int $limit = 30,
        array $columns = [],
        array $titles = [],
        bool $success = true,
        int $affected = 0,
        int|string|null $lastId = null
    ) {
        $this->data        = $data;
        $this->sql         = $sql;
        $this->durationMs  = $durationMs;
        $this->total       = $total;
        $this->page        = $page;
        $this->limit       = $limit;
        $this->columns     = $columns;
        $this->titles      = $titles;
        $this->success     = $success;
        $this->affected    = $affected;
        $this->lastId      = $lastId;

        $this->lastPage = $limit > 0 ? (int) ceil($total / $limit) : 1;
        $this->nextPage = ($page < $this->lastPage) ? $page + 1 : null;
        $this->prevPage = ($page > 1) ? $page - 1 : null;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'data'      => $this->data,
            'sql'       => $this->sql,
            'durationMs'=> $this->durationMs,
            'total'     => $this->total,
            'page'      => $this->page,
            'lastPage'  => $this->lastPage,
            'limit'     => $this->limit,
            'nextPage'  => $this->nextPage,
            'prevPage'  => $this->prevPage,
            'columns'   => $this->columns,
            'titles'    => $this->titles,
            'success'   => $this->success,
            'affected'  => $this->affected,
            'lastId'    => $this->lastId,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_UNESCAPED_UNICODE);
    }
}