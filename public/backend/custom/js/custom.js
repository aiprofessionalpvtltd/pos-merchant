$(document).ready(function () {
    // Check if jQuery is loaded
    if (typeof $ === 'undefined') {
        console.error('jQuery is not loaded.');
        return;
    }

    // Check if Notyf is loaded
    if (typeof Notyf === 'undefined') {
        console.error('Notyf is not loaded.');
        return;
    }



    // Fade out alerts
    $(".alert").fadeTo(4000, 2000).slideUp(2000, function () {
        $(".alert").slideUp(1500);
    });

    // Initialize Notyf
    const notyf = new Notyf();

    // Access session messages from the global `window.sessionMessages` object
    const messages = window.sessionMessages || {};

    if (messages.success) {
        notyf.open({
            type: 'success',
            message: messages.success,
            duration: 5000,
            ripple: true,
            dismissible: true,
            position: {x: 'right', y: 'top'}
        });
    }

    if (messages.error) {
        notyf.open({
            type: 'error',
            message: messages.error,
            duration: 5000,
            ripple: true,
            dismissible: true,
            position: {x: 'right', y: 'top'}
        });
    }

    if (messages.info) {
        notyf.open({
            type: 'info',
            message: messages.info,
            duration: 5000,
            ripple: true,
            dismissible: true,
            position: {x: 'right', y: 'top'}
        });
    }

    // Initialize DataTables
    if ($.fn.DataTable) {
        $(".datatables-reponsive").DataTable({
            responsive: true
        });
    } else {
        console.error('DataTables is not loaded.');
    }


    if ($.fn.select2) {
        $('.select2').select2({
            placeholder: "Select Option",
            allowClear: true
        });
    } else {
        console.error('Select2 is not loaded.');
    }
    // Initialize Select2



});
