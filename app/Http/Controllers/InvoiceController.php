<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = DB::connection('oracle')
            ->table('SALES_BILL')
            ->select([
                'BILL_NO',
                'YEAR',
                'DAT',
                'CUST_NO',
                'CUST_NAME',
                'TOTAL',
                'DISCOUNT',
                'NET',
                'AMOUNT_PAID',
                DB::raw('(NET - AMOUNT_PAID) AS REMAINING'),
                'STORE_NO',
                'HANDING_DONE',
            ])
            ->orderByDesc('DAT')
            ->get();

        return response()->json($invoices);
    }
    public function show($billNo)
{
    $invoice = DB::connection('oracle')
        ->table('SALES_BILL')
        ->select([
            'BILL_NO',
            'YEAR',
            'DAT',
            'CUST_NO',
            'CUST_NAME',
            'TOTAL',
            'DISCOUNT',
            'NET',
            'AMOUNT_PAID',
            DB::raw('(NET - AMOUNT_PAID) AS REMAINING'),
            'STORE_NO',
            'HANDING_DONE',
        ])
        ->where('BILL_NO', $billNo)
        ->first();

    return response()->json($invoice);
}
}