$(document).ready(function () {

// ajax call for change status of active/suspend

    $('body').on('click', '.change-status-record', function () {
        var label = $(this).data('label');

        Swal.fire({
            title: 'Are you sure?',
            text: "You want to " + label + " this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: "Yes",
            cancelButtonText: "No"
        }).then((result) => {
            if (result.isConfirmed) {
                var url = $(this).data('url');
                var id = $(this).data('id');
                var status = $(this).data('status');

                $.ajax({
                    url: url,
                    type: "POST",
                    cache: false,
                    dataType: "json",
                    data: {"_token": CSRF_TOKEN, 'id': id, 'status': status},
                    success: function (data) {
                        Swal.fire({
                            title: data.success,
                            icon: 'success',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload()
                        });
                    },
                    error: function (xhr, status, error) {
                        Swal.fire({
                            title: 'Error!',
                            text: 'An error occurred while processing your request.',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                });
            }
        });
    });


});


