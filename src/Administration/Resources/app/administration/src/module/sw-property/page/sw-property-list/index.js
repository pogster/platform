import template from './sw-property-list.html.twig';
import './sw-property-list.scss';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('sw-property-list', {
    template,

    inject: [
        'repositoryFactory',
        'acl',
    ],

    mixins: [
        Mixin.getByName('listing'),
    ],

    data() {
        return {
            propertyGroup: null,
            sortBy: 'name',
            isLoading: false,
            sortDirection: 'ASC',
            showDeleteModal: false,
            searchConfigEntity: 'property',
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        propertyRepository() {
            return this.repositoryFactory.create('property_group');
        },

        defaultCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection, this.useNaturalSorting));
            criteria.addAssociation('options');

            return criteria;
        },

        useNaturalSorting() {
            return this.sortBy === 'property.name';
        },
    },

    methods: {
        onDelete(id) {
            this.showDeleteModal = id;
        },

        onCloseDeleteModal() {
            this.showDeleteModal = false;
        },

        onConfirmDelete(id) {
            this.showDeleteModal = false;

            return this.propertyRepository.delete(id).then(() => {
                this.getList();
            });
        },

        onChangeLanguage() {
            this.getList();
        },

        getList() {
            this.isLoading = true;

            const criteria = this.addQueryScores(this.term, this.defaultCriteria);

            return this.propertyRepository.search(criteria).then((items) => {
                this.total = items.total;
                this.propertyGroup = items;
                this.isLoading = false;

                return items;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        getPropertyColumns() {
            return [{
                property: 'name',
                label: 'sw-property.list.columnName',
                routerLink: 'sw.property.detail',
                inlineEdit: 'string',
                allowResize: true,
                primary: true,
            }, {
                property: 'options',
                label: 'sw-property.list.columnOptions',
                allowResize: true,
            }, {
                property: 'description',
                label: 'sw-property.list.columnDescription',
                allowResize: true,
            }, {
                property: 'filterable',
                label: 'sw-property.list.columnFilterable',
                inlineEdit: 'boolean',
                allowResize: true,
                align: 'center',
            }];
        },
    },
});
