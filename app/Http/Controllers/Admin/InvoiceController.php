<?php

namespace App\Http\Controllers\Admin;


use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\InvoiceDocumentService;
use App\Services\InvoicePaymentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use App\Models\Sale;
use Carbon\Carbon;
use DB;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Middlewares\PermissionMiddleware;
use Yajra\DataTables\DataTables;


class InvoiceController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-invoice'])->only(['index', 'show', 'document', 'pdf']);
        $this->middleware(['permission:edit-invoice'])->only(['edit', 'update', 'resetID', 'changePassword', 'change', 'confirmCash']);
        $this->middleware(['permission:create-invoice'])->only(['create', 'store']);
        $this->middleware(['permission:delete-invoice'])->only('destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request, InvoiceDocumentService $documents)
    {
        if ($request->ajax()) {
            $invoices = Invoice::with('merchant')->select('invoices.*');

            return DataTables::of($invoices)
                ->filter(fn ($query) => $query->when($request->input('status'), fn ($inner, $status) => $inner->where('status', $status)), true)
                ->addColumn('invoice_no', fn ($invoice) => $documents->number($invoice))
                ->filterColumn('invoice_no', function ($query, $keyword) {
                    // "INV-000123", "123" and "000123" all find invoice 123
                    $digits = (int) preg_replace('/\D/', '', $keyword);

                    $digits > 0 ? $query->where('invoices.id', $digits) : $query->whereRaw('1 = 0');
                })
                ->orderColumn('invoice_no', 'invoices.id $1')
                ->addColumn('merchant', fn ($invoice) => $documents->listRow($invoice)['merchant'])
                ->filterColumn('merchant', fn ($query, $keyword) => $query->where(function ($inner) use ($keyword) {
                    $inner->where('first_name', 'like', "%{$keyword}%")
                        ->orWhere('last_name', 'like', "%{$keyword}%")
                        ->orWhereHas('merchant', fn ($merchant) => $merchant->where('business_name', 'like', "%{$keyword}%")
                            ->orWhere('first_name', 'like', "%{$keyword}%")
                            ->orWhere('last_name', 'like', "%{$keyword}%"));
                }))
                ->addColumn('amount', fn ($invoice) => $documents->listRow($invoice)['amount'])
                ->addColumn('method', fn ($invoice) => $documents->listRow($invoice)['method'])
                ->editColumn('created_at', fn ($invoice) => $invoice->created_at?->format('d M Y H:i'))
                ->addColumn('action', function ($invoice) use ($documents) {
                    $buttons = '';

                    if ($documents->isAvailable($invoice)) {
                        $buttons .= '<a class="btn btn-sm btn-outline-primary me-1" target="_blank" title="View invoice" href="' . route('admin.invoices.document', $invoice->id) . '">View</a>'
                            . '<a class="btn btn-sm btn-primary me-1" title="Download PDF" href="' . route('admin.invoices.pdf', $invoice->id) . '">PDF</a>';
                    }

                    $isPendingCash = $invoice->rail === 'cash' && $invoice->status === 'Pending' && $invoice->type === 'Subscription';

                    if ($isPendingCash && auth()->user()->can('edit-invoice')) {
                        $buttons .= '<button type="button" class="btn btn-sm btn-success confirm-cash" data-url="' . route('admin.invoices.confirm-cash', $invoice->id) . '">Confirm cash received</button>';
                    }

                    return $buttons;
                })
                // Only the buttons are HTML; everything else is escaped, since names and numbers are user input.
                ->rawColumns(['action'])
                ->make(true);
        }

        $title = 'All Invoices';
        return view('admin.invoice.index', compact('title'));
    }

    /**
     * The invoice as a printable page.
     */
    public function document(Invoice $invoice, InvoiceDocumentService $documents)
    {
        abort_unless($documents->isAvailable($invoice), 404, 'An invoice is available once it is paid.');

        return view('admin.pdf.invoice', [
            'invoice' => $documents->document($invoice),
            'isPdf' => false,
            'pdfUrl' => route('admin.invoices.pdf', $invoice->id),
        ]);
    }

    /**
     * The same invoice as a downloadable PDF.
     */
    public function pdf(Invoice $invoice, InvoiceDocumentService $documents)
    {
        abort_unless($documents->isAvailable($invoice), 404, 'An invoice is available once it is paid.');

        return Pdf::loadView('admin.pdf.invoice', [
            'invoice' => $documents->document($invoice),
            'isPdf' => true,
            'pdfUrl' => null,
        ])->setPaper('a4')
            ->setOption(['enable_font_subsetting' => true]) // embeds only the glyphs used: ~25 KB instead of ~880 KB
            ->download($documents->filename($invoice));
    }

    /**
     * Staff confirm they received the cash for a pending cash subscription payment.
     */
    public function confirmCash(Invoice $invoice, InvoicePaymentService $payments)
    {
        try {
            $payments->confirmCash($invoice);
        } catch (ApiException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->status);
        }

        Log::info('Cash payment confirmed', ['invoice' => $invoice->public_id, 'confirmed_by' => auth()->id()]);

        return response()->json(['success' => true, 'message' => 'Cash payment confirmed. The plan has started.']);
    }





}
