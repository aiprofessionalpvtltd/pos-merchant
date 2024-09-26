<?php

use App\Models\Allotee;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

function getSettingValue($val)
{
    $settings = Setting::getSetting();
    return $settings->$val;
}


function showStatus($status)
{

    if ($status == 1) {
        echo '<span class="badge badge-success">Active</span>';

    } else {
        echo '<span class="badge badge-danger">InActive</span>';

    }

}

function showApproval($status)
{

    if ($status == 1) {
        echo '<span class="badge bg-success rounded-pill">Approved</span>';

    } else {
        echo '<span class="badge bg-danger rounded-pill">Not Approved</span>';

    }

}

function showConfirmation($status)
{

    if ($status == 1) {
        echo '<span class="badge bg-success rounded-pill">Confirmed</span>';

    } else {
        echo '<span class="badge bg-danger rounded-pill">Not Confirmed</span>';

    }

}

function showVerification($status)
{

    if ($status == 1) {
        echo '<span class="badge bg-success rounded-pill">Verified</span>';

    } else {
        echo '<span class="badge bg-danger rounded-pill">Not Verified</span>';

    }

}


function showBoolean($status)
{

    if ($status == 1) {
        echo '<span class="badge bg-success rounded-pill">YES</span>';

    } else {
        echo '<span class="badge bg-danger rounded-pill">NO</span>';

    }

}

function showBooleanStatus($status)
{

    if ($status == 1) {
        return '<span class="badge badge-success">YES</span>';

    } else {
        return '<span class="badge badge-danger">NO</span>';

    }

}


function showCurrency($currency, $amount)
{
    if ($amount) {
        return $amount . ' ' . $currency;
    }
    return '-';
}


function currentDate()
{
    return date('d-M-Y');
}

function currentDatePicker()
{
    return date('m/d/Y');
}

function currentDateInput()
{
    return date('mm/dd/yyyy');
}

function currentDateInsert()
{
    return date('Y-m-d');
}

function currentDateTimeInsert()
{
    return date('Y-m-d h:i:s');
}


function currentYear()
{
    return date('Y');
}

function currentMonthStart()
{
    return date('Y-m-01');
}

function currentMonthEnd()
{
    return date('Y-m-t');
}

function currentMonth()
{
    return date('m');
}

function dateInsert($obj)
{
    return date('Y-m-d', strtotime($obj,));
}

function monthInsert($obj)
{
    return date('m', strtotime($obj));
}

function yearInsert($obj)
{
    return date('Y', strtotime($obj));
}

function showDatePicker($obj)
{
//    return date('d/m/Y', strtotime($obj));
    return date('m/d/Y', strtotime($obj));
}

function showDate($obj)
{
    if ($obj != '0000-00-00') {

        return date('d-M-Y', strtotime($obj));
    } else {
        return '';
    }
}

function showDateTime($obj)
{
    return date('d-M-Y h:i:s', strtotime($obj));
}

function showMonth($obj)
{
    return date('M', strtotime($obj));
}


function ShowFullName($firstName, $lastName)
{
    return $firstName . ' ' . $lastName;
}


function inputMaskDash($obj)
{
    return str_replace('-', '', $obj);
}

function validateTimeStamp($datetime, $full = false)
{
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

function calculatePercentage($score, $total)
{
    $formatted_percentage = '';
    if ($total != 0) {
        $percentage = ($score / $total) * 100;
    } else {
        // handle division by zero error
        $percentage = 0; // or some other default value
    }


    if ($percentage <= 100) {
        $formatted_percentage = number_format($percentage, 2);
    } else {
        $formatted_percentage = number_format(0, 2);

    }
    return $formatted_percentage;
}

function generateTransactionCode()
{
    $number = mt_rand(100000000000000, 999999999999999);
    return $number;
}

function convertShillingToUSD($shillings)
{
    // Define the new conversion rate (9200 Shillings = 1 USD)
    $conversionRate = 9200;

    // Convert shillings to USD
    $usd = $shillings / $conversionRate;

    // Return the converted value, formatted to 2 decimal places
    return round($usd, 2);
}

function convertUSDToShilling($usd)
{
    // Define the new conversion rate (1 USD = 9200 Shillings)
    $conversionRate = 9200;

    // Convert USD to Shillings
    $shillings = $usd * $conversionRate;

    // Return the converted value
    return round($shillings, 2);
}




