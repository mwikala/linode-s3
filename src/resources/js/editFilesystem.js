$(document).ready(function () {
    var ls3ChangeExpiryValue = function () {
        var parent = $(this).parents('.field'),
            amount = parent.find('.ls3-expires-amount').val(),
            period = parent.find('.ls3-expires-period select').val();

        var combinedValue = (parseInt(amount, 10) === 0 || period.length === 0) ? '' : amount + ' ' + period;

        parent.find('[type=hidden]').val(combinedValue);
    };

    $('.ls3-expires-amount').keyup(ls3ChangeExpiryValue).change(ls3ChangeExpiryValue);
    $('.ls3-expires-period select').change(ls3ChangeExpiryValue);
});
