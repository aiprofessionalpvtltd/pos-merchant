/* ------------------------------------------------------------------------------
 *
 *  # Steps wizard
 *
 *  Demo JS code for form_wizard.html page
 *
 * ---------------------------------------------------------------------------- */


// Setup module
// ------------------------------

var FormWizard = function () {

    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    });

    // Get the element to be put in fullscreen mode
    var element = document.documentElement;

// Function to enter fullscreen mode
    function enterFullscreen() {

        $('.test-restriction').addClass('fullscreen-restrict-clicks')
        $('.test-restriction').addClass('d-none')
        if (element.requestFullscreen) {
            element.requestFullscreen();
        } else if (element.webkitRequestFullscreen) {
            element.webkitRequestFullscreen();
        } else if (element.msRequestFullscreen) {
            element.msRequestFullscreen();
        }
    }

// Function to exit fullscreen mode
    function exitFullscreen() {

        if (document.exitFullscreen()) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen()) {
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen()) {
            document.msExitFullscreen();
        }
    }

// Add event listener for the Escape key
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            $('.test-restriction').removeClass('fullscreen-restrict-clicks')
            $('.test-restriction').removeClass('d-none')
            // Exit fullscreen mode
            exitFullscreen();
        }
    });

    //
    // Setup module components
    //

    // Wizard
    function ajaxStepSubmit(url, method, formData) {
        $.ajax({

            url: url,

            type: method,

            cache: false,

            dataType: "json",

            data: formData,

            success: function (response) {
                return response.status;
            },
            error: function (err) {

                $.each(err.responseJSON.errors, function (key, value) {

                    var errorPlacement = $("input[name='" + key + "']");
                    errorPlacement.next().html(value[0]);
                    errorPlacement.next().removeClass('d-none');

                });
                return response.status;
            },

        });
    }

    function checkSessionTime() {
        let sessionTime = localStorage.getItem("timeLeft");

         if (sessionTime > 0) {
            return timeLeft = sessionTime; // session time
        } else {
            return timeLeft = 1200; // 20 minutes
        }
        // return timeLeft = 10;

    }

    var timeLeft = checkSessionTime();
    var timerId;

    function startTimer(updateUrl) {
        timerId = setInterval(function () {
            if (timeLeft <= 0) {
                clearInterval(timerId);
                var form = $('.assessment-step-form')
                form.submit();
            } else {
                var minutes = Math.floor(timeLeft / 60);
                var seconds = timeLeft % 60;
                var timeString = minutes + ":" + (seconds < 10 ? "0" : "") + seconds;

                $('#timer').text('Remaining Time : ' + timeString);
                timeLeft--;
                localStorage.setItem("timeLeft", timeLeft);
                $.ajax({
                    url: updateUrl,
                    method: 'POST',
                    data: {timeLeft: timeLeft},
                    dataType: 'json',
                    success: function (response) {
                        console.log(response)

                        // do something on success
                    }
                });
            }
        }, 1000);
    }

    function startQuizTime() {
        var url = $('#startQuizTime').val();
        var updateUrl = $('#updateQuizTime').val();
        var totalTime = checkSessionTime();
        $.ajax({
            url: url,
            type: 'POST',
            data: {totalTime: totalTime},
            dataType: 'json',
            success: function (response) {
                var remaining_time = response.remaining_time;
                startTimer(updateUrl);
            }
        });


    }

    var _componentWizard = function () {
        if (!$().steps) {
            console.warn('Warning - steps.min.js is not loaded.');
            return;
        }

        //
        // Wizard with validation
        //

        // Stop function if validation is missing
        if (!$().validate) {
            console.warn('Warning - validate.min.js is not loaded.');
            return;
        }

        // Show form
        var form = $('.assessment-step-form').show();

        // let stepCount = localStorage.getItem("step");
        // // console.log(stepCount);
        //
        // if (stepCount != null) {
        //     var startIndex = parseInt(stepCount);
        //     if (startIndex == 3) {
        //         enterFullscreen();
        //         startQuizTime();
        //     }
        // } else {
        //     var startIndex = 0;
        // }

        // console.log(localStorage.getItem("tab"))
        // if (localStorage.getItem("tab") == null) {
            var defaultTab = 'Educational Detail'
        // }
        // else {
        //     var defaultTab = localStorage.getItem("tab");
        // }

        $('#TabTitle').html(defaultTab)
        // Initialize wizard
        $('.assessment-step-form').steps({
            startIndex: 0,
            headerTag: 'h6',
            bodyTag: 'fieldset',
            forceMoveForward: false,
            titleTemplate: '<span class="number">#index#</span> #title#',
            labels: {
                previous: '<span  class="icon-arrow-left13 mr-2" /> Previous',
                next: 'Next <span  class="icon-arrow-right14 ml-2" />',
                finish: 'Finish Test <span  class="icon-arrow-right14 ml-2" />'
            },
            transitionEffect: 'fade',
            autoFocus: true,
            onStepChanging: function (event, currentIndex, newIndex) {

                var tabTitle = $('#AssessmentForm-t-' + newIndex).text();
                var tabTitle = tabTitle.substr(tabTitle.indexOf(" ") + 1);

                if (form.valid() == true) {

                    localStorage.setItem("step", newIndex);
                    localStorage.setItem("tab", tabTitle);
                    $('#TabTitle').html(tabTitle)

                }
                // console.log(form.valid() == true); return false;

                // Allways allow previous action even if the current form is not valid!
                if (currentIndex > newIndex) {
                    return true;
                }

                // Needed in some cases if the user went back (clean up)
                if (currentIndex < newIndex) {

                    // To remove error styles
                    form.find('.body:eq(' + newIndex + ') label.error').remove();
                    form.find('.body:eq(' + newIndex + ') .error').removeClass('error');
                }

                form.validate().settings.ignore = ':disabled,:hidden';

                const method = form.attr('method');
                const formData = form.serialize();

                if (currentIndex === 0 && form.valid() == true) {
                    var url = $('#educationDetailAction').val();
                    ajaxStepSubmit(url, method, formData)
                }

                if (currentIndex === 1 && form.valid() == true) {
                    $(".actions [href='#next']").html("Start Test");
                    var url = $('#internshipRoleAction').val();
                    ajaxStepSubmit(url, method, formData)
                }


                let sessionTime = localStorage.getItem("timeLeft");

                if (currentIndex === 2) {
                    clearInterval(timerId)
                }

                if (newIndex === 3) {
                    enterFullscreen();
                    //start quiz time
                    var updateUrl = $('#updateQuizTime').val();
                    startTimer(updateUrl);
                }


                // return false;
                return form.valid();
            },
            onFinishing: function (event, currentIndex) {

                form.validate().settings.ignore = ':disabled,:hidden';

                return form.valid();
            },
            onFinished: function (event, currentIndex) {
                clearInterval(timerId);
                localStorage.clear();
                localStorage.removeItem("timeLeft");
                localStorage.removeItem("step");
                localStorage.removeItem("myCheckboxState");
                Swal.fire({

                    title: 'Do you wish to submit your application? ',
                    // text: "Please recheck and make sure all details entered in the education and internship sections are correct and complete.",
                    html: '<ul>' +
                        '<li class="text-justify mb-2 text-danger">Please recheck and make sure all details entered in the education and internship sections are correct and complete.</li>' +
                        '<li class="text-justify mb-2 text-danger">Make sure you have relevant field/degree, in case of mistake or wrong information provided, the candidate will not be considered</li>' +
                        '<li class="text-justify mb-2 text-danger">For each question, mark one box only to indicate the answer that you consider correct.</li>' +
                        '</ul>',

                    showCancelButton: true,

                    confirmButtonColor: '#3085d6',

                    cancelButtonColor: '#d33',

                    confirmButtonText: "Yes",

                    cancelButtonText: "No"

                }).then((result) => {

                    if (result.isConfirmed) {
                         form.submit();
                    } else {
                         return false;
                    }


                });
             }
        });


        jQuery.validator.addMethod("lettersonly", function (value, element) {
            return this.optional(element) || /^[a-z ]+$/i.test(value);
        }, "You can enter only letters and space");

        jQuery.validator.addMethod("numbersonly", function (value, element) {
            return this.optional(element) || /^[0-9]+$/i.test(value);
        }, "You can enter only numbers");

        jQuery.validator.addMethod("decimalsonly", function (value, element) {
            return this.optional(element) || /^[0-9.]+$/i.test(value);
        }, "You can enter only decimal");


        // $.extend( $.validator.prototype, {
        //     checkForm: function() {
        //         this.prepareForm();
        //         for ( var i = 0, elements = (this.currentElements = this.elements()); elements[i]; i++ ) {
        //             if (this.findByName( elements[i].name ).length != undefined && this.findByName( elements[i].name ).length > 1) {
        //                 for (var cnt = 0; cnt < this.findByName( elements[i].name ).length; cnt++) {
        //                     this.check( this.findByName( elements[i].name )[cnt] );
        //                 }
        //             }
        //             else {
        //                 this.check( elements[i] );
        //             }
        //         }
        //         return this.valid();
        //     },
        // });


        // Initialize validation
        $('.assessment-step-form').validate({
            ignore: 'input[type=hidden], .select2-search__field', // ignore hidden fields
            errorClass: 'validation-invalid-label',
            highlight: function (element, errorClass) {
                $(element).removeClass(errorClass);
            },
            unhighlight: function (element, errorClass) {
                $(element).removeClass(errorClass);
            },

            // Different components require proper error label placement
            errorPlacement: function (error, element) {

                console.log(error)
                // Unstyled checkboxes, radios
                if (element.parents().hasClass('form-check')) {
                    error.appendTo(element.parents('.form-check').parent());
                }

                // Input with icons and Select2
                else if (element.parents().hasClass('form-group-feedback') || element.hasClass('select2-hidden-accessible')) {
                    error.appendTo(element.parent());
                }

                // Input group, styled file input
                else if (element.parent().is('.uniform-uploader, .uniform-select') || element.parents().hasClass('input-group')) {
                    error.appendTo(element.parent().parent());
                }

                // Other elements
                else {
                    error.insertAfter(element);
                }
            },
            rules: {
                email: {
                    email: true
                },


                'degree_title[]': {
                    lettersonly: true
                },
                'institution[]': {
                    lettersonly: true
                },

                'cgpa[]': {
                    decimalsonly: true
                },
                'total_marks[]': {
                    decimalsonly: true
                },
                'percentage[]': {
                    numbersonly: true
                },
            }
        });
    };

    // Uniform
    var _componentUniform = function () {
        if (!$().uniform) {
            console.warn('Warning - uniform.min.js is not loaded.');
            return;
        }

        // Initialize
        $('.form-input-styled').uniform({
            fileButtonClass: 'action btn bg-blue'
        });
    };

    // Select2 select
    var _componentSelect2 = function () {
        if (!$().select2) {
            console.warn('Warning - select2.min.js is not loaded.');
            return;
        }

        // Initialize
        var $select = $('.form-control-select2').select2({
            minimumResultsForSearch: Infinity,
            width: '100%'
        });

        // Trigger value change when selection is made
        $select.on('change', function () {
            $(this).trigger('blur');
        });
    };


    //
    // Return objects assigned to module
    //

    return {
        init: function () {
            _componentWizard();
            _componentUniform();
            _componentSelect2();
        }
    }
}();


// Initialize module
// ------------------------------

document.addEventListener('DOMContentLoaded', function () {
    FormWizard.init();
});
