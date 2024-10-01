var $ = jQuery.noConflict();

var ArchivarixExternalImagesImporter_options = {
    run: function () {
        this.addTabs();
        this.toggleTabs();
        this.init();
    },
    init: function () {
        var hash = window.location.hash;
        var selector = $("#ArchivarixExternalImagesImporter-options-tabs-wrap");


        if (!hash) {
            var index = selector.find('a.nav-tab:first').addClass('nav-tab-active').attr('data-index');
            $("#ArchivarixExternalImagesImporter__tab-item-" + index).addClass('show')
        } else {
            selector.find("a[href$='" + hash + "']").addClass('nav-tab-active');
            $(hash).addClass('show');
        }
    },
    addTabs: function () {
        var tabs = [];
        var i = 0;
        $('#ArchivarixExternalImagesImporter-options').children('.form-table, .form-table:parent').each(function () {
            i++;
            var start = $(this).prev();
            var end = $(this);
            tabs[i] = start.text();

            start.add(end).wrapAll(
                "<div id='ArchivarixExternalImagesImporter__tab-item-" + i + "'" +
                " class='ArchivarixExternalImagesImporter__tab-item'>"
            );
        });

        var tabsHtml = '';


        $.each(tabs, function (index, val) {
            if (val) {
                tabsHtml += "<a data-index='" + index + "'" +
                    " id='ArchivarixExternalImagesImporter__tab-" + index + "' " +
                    "class='nav-tab' " +
                    "href='" + window.location + "#ArchivarixExternalImagesImporter__tab-item-" + index + "'>"
                    + val +
                    "</a>";
            }
        });

        $('#ArchivarixExternalImagesImporter-options').before(
            '<h2 class="nav-tab-wrapper" id="ArchivarixExternalImagesImporter-options-tabs-wrap">' + tabsHtml + '</h2>'
        );
    },
    toggleTabs: function () {
        var selector = $("#ArchivarixExternalImagesImporter-options-tabs-wrap");

        selector.find('a.nav-tab').on('click', function (e) {
            e.preventDefault();
            var index = $(this).attr('data-index');
            selector.find('a.nav-tab').removeClass('nav-tab-active');
            window.location.hash = "ArchivarixExternalImagesImporter__tab-item-" + index;

            $(this).addClass('nav-tab-active');

            $(".ArchivarixExternalImagesImporter__tab-item").removeClass('show');
            $("#ArchivarixExternalImagesImporter__tab-item-" + index).addClass('show')

        })
    }
};

$(document).ready(function () {
    ArchivarixExternalImagesImporter_options.run();
});