import React from 'react';
import Layout from '@/Layouts/AppLayout';

export default function Dashboard({ user }) {
    return (
        <Layout>
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            Welcome to Ajoo! You're logged in as {user.name}
                        </div>
                    </div>
                </div>
            </div>
        </Layout>
    );
}
