$(document).ready(function () {
    var spacesChangeExpiryValue = function () {
        var parent = $(this).parents('.field'),
            amount = parent.find('.ls3-expires-amount').val(),
            period = parent.find('.ls3-expires-period select').val();

        var combinedValue = (parseInt(amount, 10) === 0 || period.length === 0) ? '' : amount + ' ' + period;

        parent.find('[type=hidden]').val(combinedValue);
    };

    $('.ls3-expires-amount').keyup(spacesChangeExpiryValue).change(spacesChangeExpiryValue);
    $('.ls3-expires-period select').change(spacesChangeExpiryValue);
});