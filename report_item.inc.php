<?php
global $base;

// Provide a short (less than 50 characters) description
$report_description="Compare AWS instances to data stored in ONA.";


if ($extravars['window_name'] == 'display_subnet') {
  $row_html .= <<<EOL
        <tr title="{$report_description}">
            <td class="padding" align="right" nowrap="true">AWS Audit:
            <a onClick="xajax_window_submit('work_space', 'xajax_window_submit(\'display_report\', \'report=>amazon_aws_audit,subnet=>{$record['ip_addr']},subnet_type=>{$record['type']}\', \'display\')');"
            >View Report</a>

            </td>
        </tr>
EOL;
}
?>
