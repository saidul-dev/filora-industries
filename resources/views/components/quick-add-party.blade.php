{{-- "+ New" link plus modal that creates a customer/supplier in place and
     selects it in the <x-searchable-select> named $target. --}}
@props([
    'role',
    'target' => 'party_id',
])

@php
    $label = $role === 'supplier' ? __('Supplier') : __('Customer');
@endphp

@can('parties.create')
<span x-data="{
        open: false,
        saving: false,
        errors: {},
        form: { name: '', phone: '', contact_person: '', email: '', address: '' },
        show() {
            this.open = true;
            this.errors = {};
            this.$nextTick(() => this.$refs.name.focus());
        },
        async save() {
            this.saving = true;
            this.errors = {};
            try {
                const res = await fetch(@js(route('parties.quick-store')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ ...this.form, role: @js($role) }),
                });
                const data = await res.json();
                if (! res.ok) {
                    this.errors = data.errors || { name: [data.message || @js(__('Could not save.'))] };
                    return;
                }
                this.$dispatch('searchable-select-add', { name: @js($target), option: data });
                this.form = { name: '', phone: '', contact_person: '', email: '', address: '' };
                this.open = false;
            } catch (e) {
                this.errors = { name: [@js(__('Could not save — check your connection and try again.'))] };
            } finally {
                this.saving = false;
            }
        },
    }">
    <button type="button" @click="show()"
            class="inline-flex items-center gap-1 text-xs font-semibold text-accent-600 hover:text-accent-700">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        {{ __('New :role', ['role' => $label]) }}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak x-transition.opacity @keydown.escape.window="open = false"
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <form @submit.prevent="save()" @click.outside="open = false"
                  class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="font-bold text-brand-900">{{ __('New :role', ['role' => $label]) }}</h3>
                <p class="mb-4 text-xs text-slate-400">{{ __('The essentials only — fill in the rest later under Parties.') }}</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Name')" />
                        <input type="text" x-ref="name" x-model="form.name" required maxlength="255"
                               class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <p x-show="errors.name" x-text="errors.name?.[0]" class="mt-1 text-sm text-red-600"></p>
                    </div>
                    <div>
                        <x-input-label :value="__('Phone')" />
                        <input type="text" x-model="form.phone" required maxlength="30"
                               class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <p x-show="errors.phone" x-text="errors.phone?.[0]" class="mt-1 text-sm text-red-600"></p>
                    </div>
                    <div>
                        <x-input-label :value="__('Contact Person (optional)')" />
                        <input type="text" x-model="form.contact_person" maxlength="255"
                               class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <p x-show="errors.contact_person" x-text="errors.contact_person?.[0]" class="mt-1 text-sm text-red-600"></p>
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Email (optional)')" />
                        <input type="email" x-model="form.email" maxlength="255"
                               class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500">
                        <p x-show="errors.email" x-text="errors.email?.[0]" class="mt-1 text-sm text-red-600"></p>
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label :value="__('Address (optional)')" />
                        <textarea x-model="form.address" rows="2" maxlength="2000"
                                  class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-accent-500 focus:ring-accent-500"></textarea>
                        <p x-show="errors.address" x-text="errors.address?.[0]" class="mt-1 text-sm text-red-600"></p>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" @click="open = false"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">{{ __('Cancel') }}</button>
                    <button type="submit" :disabled="saving"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-800 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="saving ? @js(__('Saving…')) : @js(__('Save :role', ['role' => $label]))"></span>
                    </button>
                </div>
            </form>
        </div>
    </template>
</span>
@endcan
