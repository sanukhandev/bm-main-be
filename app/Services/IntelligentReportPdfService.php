<?php

namespace App\Services;

use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

class IntelligentReportPdfService
{
    public function download(array $report, User $user): Response
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($report, $user));
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();
        $scope = preg_replace('/[^A-Za-z0-9-]+/', '-', $report['scope']['label'] ?? 'Branch');
        $filename = 'BM-Intelligent-Report-'.$scope.'-'.$report['period']['from'].'-to-'.$report['period']['to'].'.pdf';

        return response($dompdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
    }

    private function html(array $report, User $user): string
    {
        $summary = $report['summary'];
        $cards = ['operational_profit_loss' => 'Operational Profit / Loss', 'operating_margin_percent' => 'Operating Margin %', 'operating_income' => 'Operating Income', 'operating_cost' => 'Operating Cost', 'net_cash_movement' => 'Net Cash Movement', 'collection_efficiency_percent' => 'Collection Efficiency %'];
        $cardHtml = '';
        foreach ($cards as $key => $label) {
            $cardHtml .= '<div class="card"><div class="muted">'.e($label).'</div><strong>'.e((string) ($summary[$key] ?? '—')).($str = str_contains($key, 'percent') ? '%' : '').'</strong></div>';
        }
        $findings = collect($report['findings'] ?? [])->map(fn (array $finding) => '<tr><td class="severity">'.e(strtoupper($finding['severity'] ?? 'info')).'</td><td>'.e($finding['title'] ?? '').'</td><td>'.e($finding['amount'] ?? '—').'</td><td>'.e($finding['description'] ?? '').'</td></tr>')->implode('');
        $chart = $this->chart($report['trends']['income_vs_cost'] ?? []);

        return '<!doctype html><html><head><meta charset="utf-8"><style>'.$this->css().'</style></head><body><header><div class="brand">BAITHUL MADEENA</div><h1>Intelligent Report</h1><p>Financial and operational intelligence for '.e($report['scope']['label']).'</p><p class="muted">Period: '.e($report['period']['from']).' to '.e($report['period']['to']).' · Generated: '.e(now()->toDateTimeString()).' · By: '.e($user->name).'</p></header><h2>Executive Financial Position</h2><section class="grid">'.$cardHtml.'</section><h2>Financial Trend</h2>'.$chart.'<h2>Collections & Operations</h2><table><tbody><tr><th>Total Inward</th><td>'.e($summary['total_inward']).'</td><th>Total Outward</th><td>'.e($summary['total_outward']).'</td></tr><tr><th>Tenant Receivables</th><td>'.e($summary['tenant_receivables']).'</td><th>Owner Payables</th><td>'.e($summary['owner_payables']).'</td></tr><tr><th>Pending Cheques</th><td>'.e($summary['pending_cheque_value']).'</td><th>Bounced Cheques</th><td>'.e($summary['bounced_cheque_value']).'</td></tr><tr><th>Occupied / Available</th><td>'.e($summary['occupied_properties'].' / '.$summary['available_properties']).'</td><th>Expiring Agreements</th><td>'.e(($summary['expiring_agreements']['owner'] ?? 0).' owner / '.($summary['expiring_agreements']['tenant'] ?? 0).' tenant').'</td></tr></tbody></table><h2>Leakage Detection</h2><table><thead><tr><th>Severity</th><th>Finding</th><th>Exposure</th><th>Basis</th></tr></thead><tbody>'.$findings.'</tbody></table><p class="note">'.e($report['accounting_note']).'</p></body></html>';
    }

    private function chart(array $rows): string
    {
        if ($rows === []) {
            return '<p class="muted">No trend data was captured for this period.</p>';
        }
        $max = max(1, ...array_map(fn (array $row) => max((float) $row['income'], (float) $row['cost']), $rows));
        $bars = '';
        foreach (array_slice($rows, 0, 12) as $index => $row) {
            $height = max(2, (float) $row['income'] / $max * 120);
            $cost = max(2, (float) $row['cost'] / $max * 120);
            $x = 25 + ($index * 42);
            $bars .= '<rect x="'.$x.'" y="'.(145 - $height).'" width="12" height="'.$height.'" fill="#1f6b50"/><rect x="'.($x + 14).'" y="'.(145 - $cost).'" width="12" height="'.$cost.'" fill="#343a40"/><text x="'.$x.'" y="160" font-size="6">'.e(substr((string) $row['period'], 0, 7)).'</text>';
        }

        return '<svg width="100%" height="180" viewBox="0 0 540 180" role="img" aria-label="Income and operating cost trend"><line x1="20" y1="145" x2="530" y2="145" stroke="#ccd5d0"/>'.$bars.'</svg><p class="muted">Green: income · dark: operating cost</p>';
    }

    private function css(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;color:#17231e;font-size:10px;margin:34px}header{border-bottom:4px solid #1f6b50;padding-bottom:18px}.brand{color:#1f6b50;font-weight:bold;letter-spacing:2px}h1{font-size:26px;margin:10px 0 4px}h2{color:#1f6b50;border-bottom:1px solid #dce5df;padding-bottom:5px;margin-top:24px}.muted{color:#66736d}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.card{background:#f2f6f3;padding:12px;border-radius:5px}.card strong{display:block;font-size:16px;margin-top:7px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{text-align:left;border-bottom:1px solid #e1e7e3;padding:7px}th{color:#52615a;background:#f7f9f8}.severity{font-weight:bold;color:#a23b31}.note{background:#fff7df;border-left:3px solid #c7962d;padding:10px;margin-top:24px}svg{margin-top:8px}';
    }
}
