<?php

namespace QuadCompanies\QuadPayment\Models;

trait UsesPackageTable
{
    public function getTable(): string
    {
        return config('quadpayment.table_prefix', 'qp_') . $this->baseTable;
    }

    public function getConnectionName(): ?string
    {
        return config('quadpayment.connection') ?? parent::getConnectionName();
    }
}
