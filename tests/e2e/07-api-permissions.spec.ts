/**
 * API Permission Tests
 *
 * Tests different API users and their permission levels to ensure
 * proper access control is enforced via the CiviCRM REST API.
 */

import { test, expect, type APIRequestContext } from '@playwright/test';

const API_USERS = {
  admin: { username: 'admin', password: 'admin' },
  demo: { username: 'demo', password: 'demo' },
  readonly: { username: 'readonly', password: 'readonly' },
  fundraiser: { username: 'fundraiser', password: 'fundraiser' },
  eventmanager: { username: 'eventmanager', password: 'eventmanager' },
  caseworker: { username: 'caseworker', password: 'caseworker' },
  bankimporter: { username: 'bankimporter', password: 'bankimporter' },
};

/**
 * Helper function to make authenticated API requests using HTTP Basic Authentication
 * CiviCRM APIv4 expects parameters as a form-encoded 'params' field containing JSON
 */
async function apiCall(
  request: APIRequestContext,
  baseURL: string,
  endpoint: string,
  user: { username: string; password: string },
  data?: any
) {
  const credentials = `${user.username}:${user.password}`;
  const encoded = Buffer.from(credentials).toString('base64');

  const url = `${baseURL}/civicrm/ajax/api4/${endpoint}`;
  console.log('API Call:', endpoint, 'User:', user.username, 'URL:', url);

  // CiviCRM APIv4 REST endpoint expects params as a form-encoded field
  const formData: Record<string, string> = {};
  if (data && Object.keys(data).length > 0) {
    formData['params'] = JSON.stringify(data);
  }

  return await request.post(url, {
      headers: {
        'Authorization': `Basic ${encoded}`,
        'X-Requested-With': 'XMLHttpRequest',
      },
      form: formData,
    }
  );
}

test.describe('API User Permissions', () => {
  test.describe('Admin User', () => {
    test('should have full access to read contacts', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contact/get', API_USERS.admin, { limit: 5 });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      console.log('Admin Contact.get response:', JSON.stringify(data, null, 2));
      expect(data.values).toBeDefined();
      expect(data.values.length).toBeGreaterThan(0);
    });

    test('should be able to create contacts', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contact/create', API_USERS.admin, {
        values: {
          contact_type: 'Individual',
          first_name: 'API',
          last_name: 'Test Admin',
          email: `api-test-admin-${Date.now()}@example.org`,
        },
      });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
      expect(data.values.length).toBeGreaterThan(0);
      expect(data.values[0]?.contact_type).toBe('Individual');
    });
  });

  test.describe('Read-Only User', () => {
    test('should be able to read contacts', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contact/get', API_USERS.readonly, { limit: 5 });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
    });

    test('should NOT be able to create contacts', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contact/create', API_USERS.readonly, {
        values: {
          contact_type: 'Individual',
          first_name: 'Should',
          last_name: 'Fail',
          email: 'should-fail@example.org',
        },
      });

      // Should return error
      expect(response.ok()).toBe(false);
    });

    test('should be able to view contributions', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contribution/get', API_USERS.readonly, { limit: 5 });

      expect(response.ok()).toBe(true);
    });
  });

  test.describe('Fundraiser User', () => {
    test('should be able to view contributions', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contribution/get', API_USERS.fundraiser, { limit: 5 });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
    });

    test('should be able to create contributions', async ({ request, baseURL }) => {
      // First, get a contact ID
      const contactResponse = await apiCall(request, baseURL, 'Contact/get', API_USERS.fundraiser, { limit: 1 });
      expect(contactResponse.ok()).toBe(true);
      const contactData = await contactResponse.json();

      if (contactData.values && contactData.values.length > 0) {
        const contactId = contactData.values[0].id;

        const response = await apiCall(request, baseURL, 'Contribution/create', API_USERS.fundraiser, {
          values: {
            contact_id: contactId,
            financial_type_id: 1,
            total_amount: 100.00,
            receive_date: new Date().toISOString().split('T')[0],
          },
        });

        expect(response.ok()).toBe(true);
        const data = await response.json();
        expect(data.values).toBeDefined();
        expect(data.values.length).toBeGreaterThan(0);
        expect(parseFloat(data.values[0]?.total_amount)).toBe(100.00);
      }
    });
  });

  test.describe('Event Manager User', () => {
    test('should be able to view events', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Event/get', API_USERS.eventmanager, { limit: 5 });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
    });

    test('should be able to create events', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Event/create', API_USERS.eventmanager, {
        values: {
          title: `Test Event ${Date.now()}`,
          event_type_id: 1,
          start_date: '2025-12-01 10:00:00',
          is_active: true,
        },
      });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
      expect(data.values[0]?.title).toContain('Test Event');
    });
  });

  test.describe('Case Worker User', () => {
    test('should be able to view activities', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Activity/get', API_USERS.caseworker, { limit: 5 });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
    });

    test('should be able to create activities', async ({ request, baseURL }) => {
      // First, get a contact ID
      const contactResponse = await apiCall(request, baseURL, 'Contact/get', API_USERS.caseworker, { limit: 1 });
      expect(contactResponse.ok()).toBe(true);
      const contactData = await contactResponse.json();

      if (contactData.values && contactData.values.length > 0) {
        const contactId = contactData.values[0].id;

        const response = await apiCall(request, baseURL, 'Activity/create', API_USERS.caseworker, {
          values: {
            activity_type_id: 1,
            subject: `Test Activity ${Date.now()}`,
            source_contact_id: contactId,
            target_contact_id: [contactId],
          },
        });

        expect(response.ok()).toBe(true);
        const data = await response.json();
        expect(data.values).toBeDefined();
      }
    });
  });

  test.describe('Bank Importer User', () => {
    test('should be able to view contributions', async ({ request, baseURL }) => {
      const response = await apiCall(request, baseURL, 'Contribution/get', API_USERS.bankimporter, { limit: 5 });

      expect(response.ok()).toBe(true);
      const data = await response.json();
      expect(data.values).toBeDefined();
    });

    test('should be able to create activities', async ({ request, baseURL }) => {
      // First, get a contact ID
      const contactResponse = await apiCall(request, baseURL, 'Contact/get', API_USERS.bankimporter, { limit: 1 });
      expect(contactResponse.ok()).toBe(true);
      const contactData = await contactResponse.json();

      if (contactData.values && contactData.values.length > 0) {
        const contactId = contactData.values[0].id;

        const response = await apiCall(request, baseURL, 'Activity/create', API_USERS.bankimporter, {
          values: {
            activity_type_id: 1,
            subject: `Bank Import Activity ${Date.now()}`,
            source_contact_id: contactId,
            target_contact_id: [contactId],
          },
        });

        expect(response.ok()).toBe(true);
        const data = await response.json();
        expect(data.values).toBeDefined();
      }
    });
  });
});
